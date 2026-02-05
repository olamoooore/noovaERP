-- ============================================================================
-- Loyalty Module Database Schema and Seed Data
-- ============================================================================
-- Project: NoovaERP
-- Module: Loyalty Program
-- Date: 2024-12-18
-- Description: Complete database schema for loyalty program including
--              programs, tiers, customer loyalty, transactions, rewards, and redemptions
-- ============================================================================

-- ============================================================================
-- TABLE 1: loyalty_programs
-- Description: Stores loyalty program configurations
-- ============================================================================
CREATE TABLE IF NOT EXISTS loyalty_programs (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    points_per_currency DECIMAL(10, 2) DEFAULT 1.00 NOT NULL,
    currency_per_point DECIMAL(10, 2) DEFAULT 0.01 NOT NULL,
    min_redemption_points INTEGER DEFAULT 100 NOT NULL,
    max_redemption_percent DECIMAL(5, 2) DEFAULT 50.00 NOT NULL,
    points_expiry_months INTEGER DEFAULT 12 NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP
);

CREATE INDEX idx_loyalty_programs_is_active ON loyalty_programs(is_active);

COMMENT ON COLUMN loyalty_programs.points_per_currency IS 'Points earned per $1';
COMMENT ON COLUMN loyalty_programs.currency_per_point IS '$1 = 100 points';
COMMENT ON COLUMN loyalty_programs.max_redemption_percent IS 'Max % of invoice that can be discounted';

-- ============================================================================
-- TABLE 2: loyalty_tiers
-- Description: Stores loyalty tier levels (Bronze, Silver, Gold, Platinum)
-- ============================================================================
CREATE TABLE IF NOT EXISTS loyalty_tiers (
    id BIGSERIAL PRIMARY KEY,
    loyalty_program_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    min_points INTEGER NOT NULL,
    max_points INTEGER,
    earning_multiplier DECIMAL(5, 2) DEFAULT 1.00 NOT NULL,
    discount_percent DECIMAL(5, 2) DEFAULT 0.00 NOT NULL,
    benefits JSONB,
    color VARCHAR(7),
    sort_order INTEGER DEFAULT 0 NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (loyalty_program_id) REFERENCES loyalty_programs(id) ON DELETE CASCADE
);

CREATE INDEX idx_loyalty_tiers_program_id ON loyalty_tiers(loyalty_program_id);
CREATE INDEX idx_loyalty_tiers_sort_order ON loyalty_tiers(sort_order);

COMMENT ON COLUMN loyalty_tiers.earning_multiplier IS '1.0x, 1.2x, 1.5x, 2.0x';
COMMENT ON COLUMN loyalty_tiers.benefits IS 'Additional benefits like priority support';
COMMENT ON COLUMN loyalty_tiers.color IS 'Hex color for UI';

-- ============================================================================
-- TABLE 3: customer_loyalty
-- Description: Stores customer loyalty accounts and points balance
-- ============================================================================
CREATE TABLE IF NOT EXISTS customer_loyalty (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL,
    loyalty_program_id BIGINT NOT NULL,
    loyalty_tier_id BIGINT,
    current_points INTEGER DEFAULT 0 NOT NULL,
    lifetime_points INTEGER DEFAULT 0 NOT NULL,
    points_earned INTEGER DEFAULT 0 NOT NULL,
    points_redeemed INTEGER DEFAULT 0 NOT NULL,
    points_expired INTEGER DEFAULT 0 NOT NULL,
    tier_upgraded_at TIMESTAMP,
    joined_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (loyalty_program_id) REFERENCES loyalty_programs(id) ON DELETE RESTRICT,
    FOREIGN KEY (loyalty_tier_id) REFERENCES loyalty_tiers(id) ON DELETE SET NULL,
    UNIQUE (customer_id)
);

CREATE INDEX idx_customer_loyalty_program_id ON customer_loyalty(loyalty_program_id);
CREATE INDEX idx_customer_loyalty_tier_id ON customer_loyalty(loyalty_tier_id);
CREATE INDEX idx_customer_loyalty_current_points ON customer_loyalty(current_points);

COMMENT ON COLUMN customer_loyalty.current_points IS 'Available points balance';
COMMENT ON COLUMN customer_loyalty.lifetime_points IS 'Total points ever earned';
COMMENT ON COLUMN customer_loyalty.points_earned IS 'Points earned before expiry';
COMMENT ON COLUMN customer_loyalty.points_redeemed IS 'Points used';
COMMENT ON COLUMN customer_loyalty.points_expired IS 'Points lost to expiry';

-- ============================================================================
-- TABLE 4: loyalty_transactions
-- Description: Stores all loyalty points transactions (earn, redeem, expire)
-- ============================================================================
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id BIGSERIAL PRIMARY KEY,
    customer_loyalty_id BIGINT NOT NULL,
    type VARCHAR(255) NOT NULL CHECK (type IN ('earn', 'redeem', 'expire', 'adjust')),
    points INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    reference_type VARCHAR(255),
    reference_id BIGINT,
    description TEXT,
    expires_at TIMESTAMP,
    created_by BIGINT,
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (customer_loyalty_id) REFERENCES customer_loyalty(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_loyalty_transactions_customer_loyalty_id ON loyalty_transactions(customer_loyalty_id);
CREATE INDEX idx_loyalty_transactions_type ON loyalty_transactions(type);
CREATE INDEX idx_loyalty_transactions_expires_at ON loyalty_transactions(expires_at);
CREATE INDEX idx_loyalty_transactions_reference ON loyalty_transactions(reference_type, reference_id);
CREATE INDEX idx_loyalty_transactions_created_at ON loyalty_transactions(created_at);

COMMENT ON COLUMN loyalty_transactions.points IS 'Positive for earn, negative for redeem/expire';
COMMENT ON COLUMN loyalty_transactions.reference_type IS 'invoice, payment, manual, expiry';
COMMENT ON COLUMN loyalty_transactions.expires_at IS 'For earn transactions';

-- ============================================================================
-- TABLE 5: loyalty_rewards
-- Description: Stores reward catalog items that can be redeemed
-- ============================================================================
CREATE TABLE IF NOT EXISTS loyalty_rewards (
    id BIGSERIAL PRIMARY KEY,
    loyalty_program_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    points_cost INTEGER NOT NULL,
    stock_quantity INTEGER,
    image_url VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP,
    FOREIGN KEY (loyalty_program_id) REFERENCES loyalty_programs(id) ON DELETE CASCADE
);

CREATE INDEX idx_loyalty_rewards_program_id ON loyalty_rewards(loyalty_program_id);
CREATE INDEX idx_loyalty_rewards_is_active ON loyalty_rewards(is_active);
CREATE INDEX idx_loyalty_rewards_points_cost ON loyalty_rewards(points_cost);

COMMENT ON COLUMN loyalty_rewards.stock_quantity IS 'NULL = unlimited';

-- ============================================================================
-- TABLE 6: loyalty_redemptions
-- Description: Stores redemption records when customers use points
-- ============================================================================
CREATE TABLE IF NOT EXISTS loyalty_redemptions (
    id BIGSERIAL PRIMARY KEY,
    customer_loyalty_id BIGINT NOT NULL,
    loyalty_reward_id BIGINT,
    loyalty_transaction_id BIGINT NOT NULL,
    points_used INTEGER NOT NULL,
    discount_amount DECIMAL(15, 2),
    invoice_id BIGINT,
    status VARCHAR(255) DEFAULT 'pending' NOT NULL CHECK (status IN ('pending', 'completed', 'cancelled')),
    redeemed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (customer_loyalty_id) REFERENCES customer_loyalty(id) ON DELETE CASCADE,
    FOREIGN KEY (loyalty_reward_id) REFERENCES loyalty_rewards(id) ON DELETE SET NULL,
    FOREIGN KEY (loyalty_transaction_id) REFERENCES loyalty_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
);

CREATE INDEX idx_loyalty_redemptions_customer_loyalty_id ON loyalty_redemptions(customer_loyalty_id);
CREATE INDEX idx_loyalty_redemptions_reward_id ON loyalty_redemptions(loyalty_reward_id);
CREATE INDEX idx_loyalty_redemptions_invoice_id ON loyalty_redemptions(invoice_id);
CREATE INDEX idx_loyalty_redemptions_status ON loyalty_redemptions(status);
CREATE INDEX idx_loyalty_redemptions_redeemed_at ON loyalty_redemptions(redeemed_at);

COMMENT ON COLUMN loyalty_redemptions.discount_amount IS 'If redeemed as discount';

-- ============================================================================
-- SEED DATA: Default Loyalty Program
-- ============================================================================
INSERT INTO loyalty_programs (
    name,
    description,
    points_per_currency,
    currency_per_point,
    min_redemption_points,
    max_redemption_percent,
    points_expiry_months,
    is_active,
    created_at,
    updated_at
) VALUES (
    'Default Loyalty Program',
    'Earn points with every purchase and unlock exclusive rewards',
    1.00,
    0.01,
    100,
    50.00,
    12,
    TRUE,
    NOW(),
    NOW()
) ON CONFLICT DO NOTHING;

-- ============================================================================
-- SEED DATA: Default Loyalty Tiers
-- ============================================================================
-- Get the program ID (assuming it's the first one)
DO $$
DECLARE
    program_id BIGINT;
BEGIN
    SELECT id INTO program_id FROM loyalty_programs WHERE name = 'Default Loyalty Program' LIMIT 1;
    
    -- Bronze Tier
    INSERT INTO loyalty_tiers (
        loyalty_program_id,
        name,
        min_points,
        max_points,
        earning_multiplier,
        discount_percent,
        benefits,
        color,
        sort_order,
        created_at,
        updated_at
    ) VALUES (
        program_id,
        'Bronze',
        0,
        999,
        1.00,
        0.00,
        '["Basic rewards access", "Birthday bonus points"]'::jsonb,
        '#CD7F32',
        1,
        NOW(),
        NOW()
    ) ON CONFLICT DO NOTHING;
    
    -- Silver Tier
    INSERT INTO loyalty_tiers (
        loyalty_program_id,
        name,
        min_points,
        max_points,
        earning_multiplier,
        discount_percent,
        benefits,
        color,
        sort_order,
        created_at,
        updated_at
    ) VALUES (
        program_id,
        'Silver',
        1000,
        4999,
        1.20,
        5.00,
        '["All Bronze benefits", "1.2x points multiplier", "5% discount on purchases", "Early access to sales"]'::jsonb,
        '#C0C0C0',
        2,
        NOW(),
        NOW()
    ) ON CONFLICT DO NOTHING;
    
    -- Gold Tier
    INSERT INTO loyalty_tiers (
        loyalty_program_id,
        name,
        min_points,
        max_points,
        earning_multiplier,
        discount_percent,
        benefits,
        color,
        sort_order,
        created_at,
        updated_at
    ) VALUES (
        program_id,
        'Gold',
        5000,
        9999,
        1.50,
        10.00,
        '["All Silver benefits", "1.5x points multiplier", "10% discount on purchases", "Free shipping", "Priority customer support"]'::jsonb,
        '#FFD700',
        3,
        NOW(),
        NOW()
    ) ON CONFLICT DO NOTHING;
    
    -- Platinum Tier
    INSERT INTO loyalty_tiers (
        loyalty_program_id,
        name,
        min_points,
        max_points,
        earning_multiplier,
        discount_percent,
        benefits,
        color,
        sort_order,
        created_at,
        updated_at
    ) VALUES (
        program_id,
        'Platinum',
        10000,
        NULL,
        2.00,
        15.00,
        '["All Gold benefits", "2x points multiplier", "15% discount on purchases", "Exclusive VIP events", "Personal account manager"]'::jsonb,
        '#E5E4E2',
        4,
        NOW(),
        NOW()
    ) ON CONFLICT DO NOTHING;
END $$;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================
-- Run these queries to verify the installation:
--
-- SELECT * FROM loyalty_programs;
-- SELECT * FROM loyalty_tiers ORDER BY sort_order;
-- SELECT COUNT(*) FROM loyalty_programs; -- Should return 1
-- SELECT COUNT(*) FROM loyalty_tiers;    -- Should return 4
--
-- ============================================================================
-- END OF FILE
-- ============================================================================
