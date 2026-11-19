<?php
/* Copyright (C) 2026 Dolibarr MCP contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       dolimcp/admin/setup.php
 *  \ingroup    dolimcp
 *  \brief      DoliMcp setup page: endpoint address, Claude Code command, internal API URL, journal.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'dolimcp@dolimcp'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// The token of a POST is checked by main.inc.php; the form below carries newToken().
if ($action == 'save') {
	$result = dolibarr_set_const($db, 'DOLIMCP_API_URL', trim(GETPOST('DOLIMCP_API_URL', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DoliMcpSetup');
$endpoint = dol_buildpath('/dolimcp/mcp.php', 2);

llxHeader('', $title);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DoliMcpEndpoint').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpEndpointAddress').'</td><td><code>'.dol_escape_htmltag($endpoint).'</code></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpClaudeCommand').'</td><td><code>'
	.dol_escape_htmltag('claude mcp add --transport http dolibarr '.$endpoint.' --header "DOLAPIKEY: <your API key>"')
	.'</code><br><span class="opacitymedium">'.$langs->trans('DoliMcpApiKeyHelp').'</span></td></tr>';
print '</table>';

print '<br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DoliMcpApiUrl').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliMcpApiUrlHelp').'</td><td>';
print '<input type="text" class="flat minwidth400" name="DOLIMCP_API_URL" value="'.dol_escape_htmltag(getDolGlobalString('DOLIMCP_API_URL')).'" placeholder="http://127.0.0.1'.dol_escape_htmltag(DOL_URL_ROOT).'/api/index.php">';
print ' <input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</td></tr>';
print '</table>';
print '</form>';

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('DoliMcpJournal').'</td></tr>';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('User').'</td><td>'.$langs->trans('DoliMcpTool').'</td><td>'.$langs->trans('DoliMcpOutcome').'</td></tr>';

$sql = 'SELECT j.datec, j.tool, j.outcome, u.login FROM '.MAIN_DB_PREFIX.'dolimcp_journal as j'
	.' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = j.fk_user'
	.' WHERE j.entity = '.((int) $conf->entity).' ORDER BY j.rowid DESC '.$db->plimit(100);
$resql = $db->query($sql);
$rows = 0;
while ($resql && ($obj = $db->fetch_object($resql))) {
	$rows++;
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td><td>'.dol_escape_htmltag((string) $obj->login)
		.'</td><td>'.dol_escape_htmltag($obj->tool).'</td><td>'.dol_escape_htmltag((string) $obj->outcome).'</td></tr>';
}
if ($rows === 0) {
	print '<tr class="oddeven"><td colspan="4" class="opacitymedium">'.$langs->trans('DoliMcpJournalEmpty').'</td></tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
