#
# Columns that TCA auto-schema CANNOT derive:
# - varbinary, blob, char(36): no TCA type maps to these SQL types
# - UNIQUE KEY, KEY: TCA does not define indexes
#
# Columns on fe_groups/fe_users are NOT listed here — they are
# auto-derived from TCA Overrides (select→varchar, number→int, passthrough→int).
#

CREATE TABLE tx_nrpasskeysfe_credential (
    fe_user int(11) unsigned NOT NULL DEFAULT 0,
    credential_id varbinary(1024) NOT NULL,
    public_key_cose blob NOT NULL,
    sign_count int(11) unsigned NOT NULL DEFAULT 0,
    user_handle varbinary(64) DEFAULT NULL,
    aaguid char(36) DEFAULT NULL,
    transports text DEFAULT NULL,
    label varchar(128) NOT NULL DEFAULT '',
    site_identifier varchar(255) NOT NULL DEFAULT '',
    storage_pid int(11) unsigned NOT NULL DEFAULT 0,
    created_at int(11) unsigned NOT NULL DEFAULT 0,
    last_used_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_at int(11) unsigned NOT NULL DEFAULT 0,
    revoked_by int(11) unsigned NOT NULL DEFAULT 0,

    UNIQUE KEY credential_id (credential_id),
    KEY fe_user (fe_user),
    KEY site_storage (site_identifier, storage_pid)
);

CREATE TABLE tx_nrpasskeysfe_recovery_code (
    fe_user int(11) unsigned NOT NULL DEFAULT 0,
    code_hash varchar(255) NOT NULL DEFAULT '',
    used_at int(11) unsigned NOT NULL DEFAULT 0,
    created_at int(11) unsigned NOT NULL DEFAULT 0,

    KEY fe_user (fe_user)
);
