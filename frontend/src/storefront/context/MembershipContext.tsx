import React, { createContext, useContext, useState } from 'react';

export type MembershipTier = 'free' | 'bronze' | 'silver' | 'gold';

interface MembershipContextType {
  tier: MembershipTier;
  setTier: (tier: MembershipTier) => void;
  discount: number;
  tierName: string;
}

const TIER_DISCOUNTS: Record<MembershipTier, number> = {
  free: 0,
  bronze: 5,
  silver: 10,
  gold: 15,
};

const TIER_NAMES: Record<MembershipTier, string> = {
  free: 'Free',
  bronze: 'Bronze',
  silver: 'Silver',
  gold: 'Gold',
};

const MembershipContext = createContext<MembershipContextType | undefined>(undefined);

export const MembershipProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [tier, setTier] = useState<MembershipTier>('free');

  const discount = TIER_DISCOUNTS[tier];
  const tierName = TIER_NAMES[tier];

  return (
    <MembershipContext.Provider value={{ tier, setTier, discount, tierName }}>
      {children}
    </MembershipContext.Provider>
  );
};

export const useMembership = () => {
  const context = useContext(MembershipContext);
  if (!context) {
    throw new Error('useMembership must be used within a MembershipProvider');
  }
  return context;
};
