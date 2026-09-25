import api from './api';

// ==================== INTERFACES ====================

export interface LoyaltyProgram {
  id: number;
  name: string;
  description?: string;
  points_per_currency: number;
  minimum_points_to_redeem: number;
  points_expiry_days?: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface LoyaltyTier {
  id: number;
  program_id: number;
  name: string;
  description?: string;
  minimum_points: number;
  points_multiplier: number;
  benefits?: string[];
  created_at: string;
  updated_at: string;
}

export interface CustomerLoyalty {
  id: number;
  customer_id: number;
  program_id: number;
  tier_id?: number;
  total_points_earned: number;
  total_points_redeemed: number;
  current_points: number;
  lifetime_value: number;
  created_at: string;
  updated_at: string;
  customer?: {
    id: number;
    name: string;
    email?: string;
    phone?: string;
  };
  tier?: LoyaltyTier;
  program?: LoyaltyProgram;
}

export interface LoyaltyTransaction {
  id: number;
  customer_loyalty_id: number;
  type: 'earned' | 'redeemed' | 'adjusted' | 'expired' | 'bonus';
  points: number;
  description?: string;
  reference_type?: string;
  reference_id?: number;
  created_at: string;
  customer_loyalty?: CustomerLoyalty;
}

export interface LoyaltyReward {
  id: number;
  program_id: number;
  name: string;
  description?: string;
  points_cost: number;
  reward_type: 'discount_fixed' | 'discount_percentage' | 'free_product' | 'free_shipping' | 'other';
  reward_value?: number;
  is_active: boolean;
  stock_quantity?: number;
  created_at: string;
  updated_at: string;
}

export interface LoyaltyRedemption {
  id: number;
  customer_loyalty_id: number;
  reward_id?: number;
  redemption_type: 'discount' | 'reward';
  points_used: number;
  value_redeemed: number;
  status: 'pending' | 'completed' | 'cancelled';
  reference_type?: string;
  reference_id?: number;
  notes?: string;
  created_at: string;
  updated_at: string;
  customer_loyalty?: CustomerLoyalty;
  reward?: LoyaltyReward;
}

export interface LoyaltyDashboardStats {
  total_customers: number;
  total_points_issued: number;
  total_points_redeemed: number;
  active_rewards: number;
  recent_redemptions: LoyaltyRedemption[];
  top_customers: CustomerLoyalty[];
}

export interface RedemptionCalculation {
  points_to_use: number;
  discount_value: number;
  remaining_points: number;
}

// ==================== API CLASS ====================

class LoyaltyApi {
  // ========== Dashboard ==========
  async getDashboardStats(): Promise<LoyaltyDashboardStats> {
    const response = await api.get('/loyalty/dashboard');
    return response.data;
  }

  // ========== Programs ==========
  async getActiveProgram(): Promise<LoyaltyProgram> {
    const response = await api.get('/loyalty/programs');
    return response.data;
  }

  async getAllPrograms(): Promise<LoyaltyProgram[]> {
    const response = await api.get('/loyalty/programs/all');
    return response.data;
  }

  async getProgram(programId: number): Promise<LoyaltyProgram> {
    const response = await api.get(`/loyalty/programs/${programId}`);
    return response.data;
  }

  async createProgram(data: Partial<LoyaltyProgram>): Promise<LoyaltyProgram> {
    const response = await api.post('/loyalty/programs', data);
    return response.data;
  }

  async updateProgram(programId: number, data: Partial<LoyaltyProgram>): Promise<LoyaltyProgram> {
    const response = await api.put(`/loyalty/programs/${programId}`, data);
    return response.data;
  }

  async getProgramTiers(programId: number): Promise<LoyaltyTier[]> {
    const response = await api.get(`/loyalty/programs/${programId}/tiers`);
    return response.data;
  }

  async getProgramStatistics(programId: number): Promise<any> {
    const response = await api.get(`/loyalty/programs/${programId}/statistics`);
    return response.data;
  }

  // ========== Customers ==========
  async getCustomers(search?: string, page?: number, perPage?: number): Promise<any> {
    const response = await api.get('/loyalty/customers', {
      params: { search, page, per_page: perPage },
    });
    return response.data;
  }

  async enrollCustomer(customerId: number): Promise<any> {
    const response = await api.post(`/loyalty/customers/${customerId}/enroll`);
    return response.data;
  }

  async getCustomer(customerId: number): Promise<CustomerLoyalty> {
    const response = await api.get(`/loyalty/customers/${customerId}`);
    return response.data;
  }

  async getCustomerDashboard(customerId: number): Promise<any> {
    const response = await api.get(`/loyalty/customers/${customerId}/dashboard`);
    return response.data;
  }

  async getCustomerTransactions(customerId: number): Promise<LoyaltyTransaction[]> {
    const response = await api.get(`/loyalty/customers/${customerId}/transactions`);
    return response.data;
  }

  async adjustPoints(customerId: number, points: number, description?: string): Promise<any> {
    const response = await api.post(`/loyalty/customers/${customerId}/adjust`, {
      points,
      description,
    });
    return response.data;
  }

  // ========== Points Earning ==========
  async earnPoints(data: {
    customer_id: number;
    amount: number;
    reference_type?: string;
    reference_id?: number;
    description?: string;
  }): Promise<any> {
    const response = await api.post('/loyalty/earn', data);
    return response.data;
  }

  // ========== Redemptions ==========
  async getRedemptions(): Promise<LoyaltyRedemption[]> {
    const response = await api.get('/loyalty/redemptions');
    return response.data;
  }

  async getRedemption(redemptionId: number): Promise<LoyaltyRedemption> {
    const response = await api.get(`/loyalty/redemptions/${redemptionId}`);
    return response.data;
  }

  async getCustomerRedemptions(customerId: number): Promise<LoyaltyRedemption[]> {
    const response = await api.get(`/loyalty/redemptions/customers/${customerId}`);
    return response.data;
  }

  async getPosRedemptionInfo(customerId: number, invoiceAmount?: number): Promise<any> {
    const response = await api.get(`/loyalty/redemptions/pos/${customerId}`, {
      params: { invoice_amount: invoiceAmount },
    });
    return response.data;
  }

  async redeemForDiscount(data: {
    customer_id: number;
    points_to_use: number;
    reference_type?: string;
    reference_id?: number;
  }): Promise<LoyaltyRedemption> {
    const response = await api.post('/loyalty/redemptions/discount', data);
    return response.data;
  }

  async redeemForReward(data: {
    customer_id: number;
    reward_id: number;
    reference_type?: string;
    reference_id?: number;
  }): Promise<LoyaltyRedemption> {
    const response = await api.post('/loyalty/redemptions/reward', data);
    return response.data;
  }

  async calculateRedemption(customerId: number, pointsToUse: number): Promise<RedemptionCalculation> {
    const response = await api.post('/loyalty/redemptions/calculate', {
      customer_id: customerId,
      points_to_use: pointsToUse,
    });
    return response.data;
  }

  async updateRedemptionStatus(redemptionId: number, status: string): Promise<LoyaltyRedemption> {
    const response = await api.put(`/loyalty/redemptions/${redemptionId}/status`, { status });
    return response.data;
  }

  async cancelRedemption(redemptionId: number): Promise<any> {
    const response = await api.post(`/loyalty/redemptions/${redemptionId}/cancel`);
    return response.data;
  }

  // ========== Rewards ==========
  async getRewards(): Promise<LoyaltyReward[]> {
    const response = await api.get('/loyalty/rewards');
    return response.data;
  }

  async getReward(rewardId: number): Promise<LoyaltyReward> {
    const response = await api.get(`/loyalty/rewards/${rewardId}`);
    return response.data;
  }

  async getRewardCatalog(customerId: number): Promise<LoyaltyReward[]> {
    const response = await api.get(`/loyalty/rewards/catalog/${customerId}`);
    return response.data;
  }

  async createReward(data: Partial<LoyaltyReward>): Promise<LoyaltyReward> {
    const response = await api.post('/loyalty/rewards', data);
    return response.data;
  }

  async updateReward(rewardId: number, data: Partial<LoyaltyReward>): Promise<LoyaltyReward> {
    const response = await api.put(`/loyalty/rewards/${rewardId}`, data);
    return response.data;
  }

  async deleteReward(rewardId: number): Promise<void> {
    await api.delete(`/loyalty/rewards/${rewardId}`);
  }
}

const loyaltyApi = new LoyaltyApi();
export default loyaltyApi;
