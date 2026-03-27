-- Add sender_rules table
CREATE TABLE IF NOT EXISTS sender_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    sender_email VARCHAR NOT NULL,
    target_folder VARCHAR NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT uix_sender_rule_user_email UNIQUE (user_id, sender_email)
);

CREATE INDEX IF NOT EXISTS idx_sender_rules_user_id ON sender_rules(user_id);
CREATE INDEX IF NOT EXISTS idx_sender_rules_sender_email ON sender_rules(sender_email);
