-- Audit journal of the MCP tool calls.
CREATE TABLE llx_dolimcp_journal (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	fk_user integer NOT NULL,
	tool varchar(64) NOT NULL,
	params text,
	outcome varchar(255)
) ENGINE=innodb;
