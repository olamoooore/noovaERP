-- DB-level triggers for audit logging on user_company_access
CREATE OR REPLACE FUNCTION log_user_company_access_changes() RETURNS trigger AS $BODY$
BEGIN
    IF (TG_OP = 'INSERT') THEN
        INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
        VALUES ('user_company_access', 'INSERT', NULL, row_to_json(NEW), NULL, NULL, NULL, now(), now());
        RETURN NEW;
    ELSIF (TG_OP = 'UPDATE') THEN
        INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
        VALUES ('user_company_access', 'UPDATE', row_to_json(OLD), row_to_json(NEW), NULL, NULL, NULL, now(), now());
        RETURN NEW;
    ELSIF (TG_OP = 'DELETE') THEN
        INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
        VALUES ('user_company_access', 'DELETE', row_to_json(OLD), NULL, NULL, NULL, NULL, now(), now());
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$BODY$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_user_company_access ON user_company_access;
CREATE TRIGGER trg_audit_user_company_access
AFTER INSERT OR UPDATE OR DELETE ON user_company_access
FOR EACH ROW EXECUTE FUNCTION log_user_company_access_changes();

-- DB-level triggers for audit logging on domains (if table exists)
DO $DO$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'domains') THEN
        EXECUTE 'CREATE OR REPLACE FUNCTION log_domains_changes() RETURNS trigger AS $$
        BEGIN
            IF (TG_OP = ''INSERT'') THEN
                INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
                VALUES (''domains'', ''INSERT'', NULL, row_to_json(NEW), NULL, NULL, NULL, now(), now());
                RETURN NEW;
            ELSIF (TG_OP = ''UPDATE'') THEN
                INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
                VALUES (''domains'', ''UPDATE'', row_to_json(OLD), row_to_json(NEW), NULL, NULL, NULL, now(), now());
                RETURN NEW;
            ELSIF (TG_OP = ''DELETE'') THEN
                INSERT INTO audit_log (table_name, operation, before_state, after_state, actor_id, ip_address, user_agent, created_at, updated_at)
                VALUES (''domains'', ''DELETE'', row_to_json(OLD), NULL, NULL, NULL, NULL, now(), now());
                RETURN OLD;
            END IF;
            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql';

        DROP TRIGGER IF EXISTS trg_audit_domains ON domains;
        CREATE TRIGGER trg_audit_domains
        AFTER INSERT OR UPDATE OR DELETE ON domains
        FOR EACH ROW EXECUTE FUNCTION log_domains_changes();
    END IF;
END $DO$;
