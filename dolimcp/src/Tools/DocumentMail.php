<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

/** Sends a proposal or an invoice by email the way the Dolibarr card does, so the sending shows in the agenda. */
final class DocumentMail
{
    // Per type: class file, class, email template, translation keys, send trigger, agenda tracking prefix, auto-copy constant.
    private const SETUP = [
        'proposal' => [
            'file' => '/comm/propal/class/propal.class.php', 'class' => 'Propal', 'template' => 'propal_send',
            'subject' => 'SendPropalRef', 'trigger' => 'PROPAL_SENTBYMAIL', 'track' => 'pro', 'autocopy' => 'MAIN_MAIL_AUTOCOPY_PROPOSAL_TO',
            'dir' => 'propal', 'pdfModel' => 'PROPALE_ADDON_PDF', 'right' => 'propal', 'advanced' => 'propal_advance',
        ],
        'invoice' => [
            'file' => '/compta/facture/class/facture.class.php', 'class' => 'Facture', 'template' => 'facture_send',
            'subject' => 'SendBillRef', 'trigger' => 'BILL_SENTBYMAIL', 'track' => 'inv', 'autocopy' => 'MAIN_MAIL_AUTOCOPY_INVOICE_TO',
            'dir' => 'facture', 'pdfModel' => 'FACTURE_ADDON_PDF', 'right' => 'facture', 'advanced' => 'invoice_advance',
        ],
    ];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        $registry->add(new Tool(
            'document_send',
            'Send a validated proposal or invoice by email, with its PDF, and log the sending in the agenda. to defaults to the third party email; subject and message default to the Dolibarr email template of the document (substitutions applied). to and cc take comma-separated addresses.',
            [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['proposal', 'invoice']],
                    'id' => ['type' => 'integer'],
                    'to' => ['type' => 'string'],
                    'cc' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'attach_pdf' => ['type' => 'boolean'],
                ],
                'required' => ['type', 'id'],
                'additionalProperties' => false,
            ],
            static fn (array $a): ToolResult => ToolResult::ok(self::send($context, $a)),
            true,
        ));
    }

    private static function send(Context $context, array $a): array
    {
        global $conf, $langs, $mysoc, $dolibarr_main_url_root;

        $setup = self::SETUP[(string) $a['type']];
        $id = (int) $a['id'];
        $user = $context->user;
        $db = $context->db;

        // Rights of the card: read, plus the "send" advanced permission when advanced permissions are on.
        $advanced = getDolGlobalString('MAIN_USE_ADVANCED_PERMS');
        if (!$user->hasRight($setup['right'], 'lire') || ($advanced && !$user->hasRight($setup['right'], $setup['advanced'], 'send'))) {
            throw new ToolError('Your Dolibarr user may not send this kind of document by email.');
        }
        require_once DOL_DOCUMENT_ROOT . $setup['file'];
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
        $langs->loadLangs(['mails', 'commercial', 'bills', 'other']);

        $object = new $setup['class']($db);
        if ($object->fetch($id) <= 0 || !checkUserAccessToObject($user, [$setup['dir']], $id)) {
            throw new ToolError("The $a[type] $id was not found.");
        }
        if ((int) $object->status <= 0) {
            throw new ToolError('Validate the ' . $a['type'] . ' before sending it.');
        }
        $object->fetch_thirdparty();
        $thirdparty = $object->thirdparty;

        $to = trim((string) ($a['to'] ?? '')) ?: (string) ($thirdparty->email ?? '');
        if ($to === '') {
            throw new ToolError('No recipient: pass "to" or fill in the email of the third party.');
        }
        $cc = trim((string) ($a['cc'] ?? ''));
        $bcc = getDolGlobalString($setup['autocopy']);

        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM') ?: (string) $user->email ?: getDolGlobalString('MAIN_INFO_SOCIETE_MAIL');
        if ($from === '') {
            throw new ToolError('No sender address: set the email of the sender in Dolibarr (Setup > Emails).');
        }
        $from = dol_string_nospecial((string) $mysoc->name, ' ', [',']) . ' <' . $from . '>';

        $files = ($a['attach_pdf'] ?? true) ? [self::pdf($context, $object, $setup)] : [];

        [$subject, $message] = self::text($context, $object, $setup, $a, $to);
        $trackid = $setup['track'] . $id;
        $tmpdir = $conf->user->dir_output . '/' . $user->id . '/temp';
        $mail = new \CMailFile($subject, $to, $from, $message, array_column($files, 'path'), array_column($files, 'mime'), array_column($files, 'name'), $cc, $bcc, 0, -1, '', '', $trackid, '', 'standard', '', $tmpdir);
        if (!empty($mail->error) || !empty($mail->errors)) {
            throw new ToolError(self::plain($mail->error, $mail->errors, 'The email could not be built.'));
        }
        if (!$mail->sendfile()) {
            throw new ToolError(self::plain((string) $mail->error, (array) $mail->errors, 'Dolibarr could not send the email; check the email setup.'));
        }

        // What core/actions_sendmails.inc.php does after a successful send, so the agenda gets its event.
        $db->begin();
        $object->socid = $thirdparty->id;
        $object->sendtoid = [];
        $object->actiontypecode = 'AC_EMAIL';
        $object->actionmsg = $message;
        $object->actionmsg2 = $langs->transnoentities('MailSentByTo', \CMailFile::getValidAddress($from, 4, 0, 1), \CMailFile::getValidAddress($to, 4, 0, 1));
        if (getDolGlobalString('MAIN_MAIL_REPLACE_EVENT_TITLE_BY_EMAIL_SUBJECT')) {
            $object->actionmsg2 = $subject;
        }
        $object->trackid = $trackid;
        $object->fk_element = $object->id;
        $object->elementtype = $object->element;
        $object->attachedfiles = ['paths' => array_column($files, 'path'), 'names' => array_column($files, 'name'), 'mimes' => array_column($files, 'mime')];
        $object->context['email_msgid'] = $mail->msgid;
        $object->context['email_from'] = $from;
        $object->context['email_subject'] = $subject;
        $object->context['email_to'] = $to;
        $object->context['email_tocc'] = $cc;
        $object->context['email_tobcc'] = $bcc;
        $object->email_msgid = $mail->msgid;
        $object->email_from = $from;
        $object->email_subject = $subject;
        $object->email_to = $to;
        $object->email_tocc = $cc;
        $object->email_tobcc = $bcc;

        if ($a['type'] === 'invoice') {
            $db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET email_sent_counter = email_sent_counter + 1 WHERE rowid = ' . ((int) $object->id));
            $object->email_sent_counter = (int) $object->email_sent_counter + 1;
        }
        if ($object->call_trigger($setup['trigger'], $user) < 0) {
            $db->rollback();
            throw new ToolError('The email was sent to ' . $to . ' but Dolibarr could not log it in the agenda: ' . DocumentTools::failure($object, 'unknown error') . ' Do not send it again.');
        }
        $db->commit();

        return ['sent' => true, 'to' => $to, 'cc' => $cc, 'subject' => $subject, 'attachment' => $files[0]['name'] ?? null];
    }

    /** @return array{path:string,name:string,mime:string} the PDF of the document, generated when missing */
    private static function pdf(Context $context, object $object, array $setup): array
    {
        global $conf, $langs;

        $ref = dol_sanitizeFileName((string) $object->ref);
        $dirs = $conf->{$setup['dir']}->multidir_output;
        $path = $dirs[$object->entity ?: $conf->entity] . "/$ref/$ref.pdf";
        if (!is_file($path)) {
            $model = (string) $object->model_pdf ?: getDolGlobalString($setup['pdfModel']);
            if ($object->generateDocument($model, $langs, 0, 0, 0) <= 0 || !is_file($path)) {
                throw new ToolError(DocumentTools::failure($object, 'Dolibarr could not generate the PDF.'));
            }
        }

        return ['path' => $path, 'name' => "$ref.pdf", 'mime' => 'application/pdf'];
    }

    /** @return array{0:string,1:string} subject and message, from the arguments or the Dolibarr email template */
    private static function text(Context $context, object $object, array $setup, array $a, string $to): array
    {
        global $langs;

        $subject = (string) ($a['subject'] ?? '');
        $message = (string) ($a['message'] ?? '');
        if ($subject === '' || $message === '') {
            $template = (new \FormMail($context->db))->getEMailTemplate($context->db, $setup['template'], $context->user, $langs, -1);
            $template = is_object($template) ? $template : null;
            $subject = $subject !== '' ? $subject : (trim((string) ($template->topic ?? '')) ?: $langs->transnoentities($setup['subject'], '__REF__'));
            $message = $message !== '' ? $message : trim((string) ($template->content ?? ''));
        }

        $substitutions = getCommonSubstitutionArray($langs, 0, null, $object);
        $substitutions['__SENDEREMAIL_SIGNATURE__'] = $context->user->signature;
        $substitutions['__EMAIL__'] = $to;
        complete_substitutions_array($substitutions, $langs, $object, ['mode' => 'formemail']);

        return [make_substitutions($subject, $substitutions), make_substitutions($message, $substitutions)];
    }

    private static function plain(string $error, array $errors, string $fallback): string
    {
        $text = trim(strip_tags(implode(' ', array_filter([$error, ...array_map('strval', $errors)]))));

        return $text === '' ? $fallback : $text;
    }
}
