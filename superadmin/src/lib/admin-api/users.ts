import { adminApi } from './client'

export interface AdminUser {
  id: number
  name: string
  email: string
  role: string
  created_at: string
}

export interface AdminInvitation {
  id: number
  email: string
  role: string
  invited_by_admin_user_id: number | null
  expires_at: string
  accepted_at: string | null
  created_at: string
  invited_by?: { id: number; name: string; email: string }
}

export interface InviteResult {
  invitation: AdminInvitation
  accept_url: string
}

export async function getAdminUsers(): Promise<{ admins: AdminUser[]; invitations: AdminInvitation[] }> {
  const res = await adminApi.get('/users')
  return res.data.data
}

export async function inviteAdminUser(email: string, role: string): Promise<InviteResult> {
  const res = await adminApi.post('/users/invite', { email, role })
  return res.data.data
}

export async function updateAdminRole(userId: number, role: string): Promise<AdminUser> {
  const res = await adminApi.put(`/users/${userId}/role`, { role })
  return res.data.data
}

export async function deactivateAdminUser(userId: number): Promise<void> {
  await adminApi.delete(`/users/${userId}`)
}
