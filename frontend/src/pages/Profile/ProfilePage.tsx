import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '@iconify/react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { useToast } from '@/hooks/use-toast'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { compressImage } from '@/lib/imageCompression'

interface ProfileResponse {
  user: {
    id: number
    name: string
    email: string
    phone?: string | null
    avatar?: string | null
  }
  avatar_url?: string | null
}

function extractErrorMessage(error: any, fallback: string): string {
  const data = error?.response?.data
  if (data?.message && typeof data.message === 'string') return data.message
  const errors = data?.errors
  if (errors && typeof errors === 'object') {
    for (const key of Object.keys(errors)) {
      const first = (errors as Record<string, unknown>)[key]
      if (Array.isArray(first) && typeof first[0] === 'string') return first[0]
      if (typeof first === 'string') return first
    }
  }
  return fallback
}

function initialsFor(name?: string | null, email?: string | null): string {
  const source = (name || email || '?').trim()
  return source
    .split(/\s+/)
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase() || '?'
}

export function ProfilePage() {
  const { user, role, tenantId, setAuth } = useAuthStore()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const profileQuery = useQuery({
    queryKey: ['profile'],
    queryFn: async (): Promise<ProfileResponse> => {
      const response = await api.get<ProfileResponse>('/auth/profile')
      return response.data
    },
  })

  const userFromQuery = profileQuery.data?.user
  const avatarUrl = profileQuery.data?.avatar_url ?? user?.avatar_url ?? null
  const displayName = userFromQuery?.name ?? user?.name ?? ''
  const displayEmail = userFromQuery?.email ?? user?.email ?? ''
  const displayPhone = userFromQuery?.phone ?? user?.phone ?? null

  const initials = useMemo(
    () => initialsFor(displayName, displayEmail),
    [displayName, displayEmail]
  )

  const pushUserIntoStore = (next: ProfileResponse['user'] | null) => {
    if (!next) return
    const token = localStorage.getItem('token')
    setAuth(
      {
        id: next.id,
        name: next.name,
        email: next.email,
        phone: next.phone ?? null,
        avatar: next.avatar ?? null,
        avatar_url: avatarUrl,
        roles: user?.roles,
      },
      token || '',
      tenantId || undefined,
      role || undefined
    )
  }

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6 p-6 md:p-8">
      <header className="flex flex-col gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          Account
        </p>
        <h1 className="text-3xl font-semibold tracking-tight">Profile</h1>
        <p className="text-sm text-muted-foreground">
          Manage how you appear across the workspace and keep your sign-in secure.
        </p>
      </header>

      <Tabs defaultValue="profile" className="w-full">
        <TabsList className="w-fit max-w-full">
          <TabsTrigger value="profile" className="gap-2">
            <Icon icon="solar:user-circle-bold-duotone" className="h-4 w-4" />
            Profile
          </TabsTrigger>
          <TabsTrigger value="security" className="gap-2">
            <Icon icon="solar:shield-keyhole-bold-duotone" className="h-4 w-4" />
            Security
          </TabsTrigger>
        </TabsList>

        <TabsContent value="profile" className="mt-6 space-y-6">
          <ProfileTab
            avatarUrl={avatarUrl}
            displayName={displayName}
            displayEmail={displayEmail}
            displayPhone={displayPhone}
            initials={initials}
            isLoading={profileQuery.isLoading}
            onProfileUpdated={(response) => {
              pushUserIntoStore(response.user)
              queryClient.setQueryData(['profile'], response)
            }}
          />
        </TabsContent>

        <TabsContent value="security" className="mt-6 space-y-6">
          <SecurityTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

interface ProfileTabProps {
  avatarUrl: string | null
  displayName: string
  displayEmail: string
  displayPhone: string | null
  initials: string
  isLoading: boolean
  onProfileUpdated: (response: ProfileResponse) => void
}

function ProfileTab({
  avatarUrl,
  displayName,
  displayEmail,
  displayPhone,
  initials,
  isLoading,
  onProfileUpdated,
}: ProfileTabProps) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const [avatarFile, setAvatarFile] = useState<File | null>(null)
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null)
  const [avatarDirty, setAvatarDirty] = useState(false)
  const [confirmRemoveOpen, setConfirmRemoveOpen] = useState(false)

  const [name, setName] = useState(displayName)
  const [email, setEmail] = useState(displayEmail)
  const [phone, setPhone] = useState(displayPhone ?? '')
  const [infoDirty, setInfoDirty] = useState(false)

  useEffect(() => {
    setName(displayName)
    setEmail(displayEmail)
    setPhone(displayPhone ?? '')
    setInfoDirty(false)
  }, [displayName, displayEmail, displayPhone])

  useEffect(() => () => {
    if (avatarPreview) URL.revokeObjectURL(avatarPreview)
  }, [avatarPreview])

  const updateProfileMutation = useMutation({
    mutationFn: async (payload: { name: string; email: string; phone: string | null }) => {
      const response = await api.put<ProfileResponse>('/auth/profile', payload)
      return response.data
    },
    onSuccess: (data) => {
      onProfileUpdated(data)
      toast({
        title: 'Profile saved',
        description: 'Your profile information has been updated.',
      })
      setInfoDirty(false)
    },
    onError: (error: any) => {
      const description = extractErrorMessage(error, 'Failed to save profile.')
      toast({ title: 'Save failed', description, variant: 'destructive' })
    },
  })

  const uploadAvatarMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('avatar', file)
      const response = await api.post<{ avatar_url: string | null }>(
        '/auth/profile/avatar',
        fd,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      )
      return response.data
    },
    onSuccess: async (data) => {
      const cached = queryClient.getQueryData<ProfileResponse>(['profile'])
      const nextUser = cached?.user ? { ...cached.user } : undefined
      onProfileUpdated({
        user: nextUser ?? {
          id: 0,
          name: displayName,
          email: displayEmail,
          phone: phone,
          avatar: null,
        },
        avatar_url: data.avatar_url ?? null,
      })
      toast({
        title: 'Avatar updated',
        description: 'Your profile picture has been updated.',
      })
      setAvatarFile(null)
      setAvatarPreview(null)
      setAvatarDirty(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    },
    onError: (error: any) => {
      const description = extractErrorMessage(error, 'Failed to upload avatar.')
      toast({ title: 'Upload failed', description, variant: 'destructive' })
    },
  })

  const removeAvatarMutation = useMutation({
    mutationFn: async () => {
      const response = await api.delete('/auth/profile/avatar')
      return response.data
    },
    onSuccess: () => {
      const cached = queryClient.getQueryData<ProfileResponse>(['profile'])
      const nextUser = cached?.user ? { ...cached.user, avatar: null } : undefined
      onProfileUpdated({
        user: nextUser ?? {
          id: 0,
          name: displayName,
          email: displayEmail,
          phone: phone,
          avatar: null,
        },
        avatar_url: null,
      })
      toast({
        title: 'Avatar removed',
        description: 'Your profile picture has been removed.',
      })
      setConfirmRemoveOpen(false)
    },
    onError: (error: any) => {
      toast({
        title: 'Removal failed',
        description: error?.response?.data?.message || 'Failed to remove avatar.',
        variant: 'destructive',
      })
    },
  })

  const handleAvatarPick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    try {
      const compressed = await compressImage(file, {
        maxWidthOrHeight: 512,
        targetMimeType: 'image/webp',
        quality: 0.85,
      })
      if (avatarPreview) URL.revokeObjectURL(avatarPreview)
      const previewUrl = URL.createObjectURL(compressed)
      setAvatarFile(compressed)
      setAvatarPreview(previewUrl)
      setAvatarDirty(true)
    } catch {
      toast({
        title: 'Could not process image',
        description: 'Please pick a different image file.',
        variant: 'destructive',
      })
    }
  }

  const handleAvatarCancel = () => {
    if (avatarPreview) URL.revokeObjectURL(avatarPreview)
    setAvatarFile(null)
    setAvatarPreview(null)
    setAvatarDirty(false)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleSubmitInfo = (e: React.FormEvent) => {
    e.preventDefault()
    updateProfileMutation.mutate({
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim() ? phone.trim() : null,
    })
  }

  const displayedAvatar = avatarDirty ? avatarPreview : avatarUrl

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:gallery-wide-bold-duotone" className="h-5 w-5 text-primary" />
            Profile picture
          </CardTitle>
          <CardDescription>
            A square image works best. We resize and compress automatically.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center">
          <Avatar className="h-24 w-24 ring-2 ring-border">
            {displayedAvatar ? (
              <AvatarImage src={displayedAvatar} alt={displayName || 'Avatar'} />
            ) : null}
            <AvatarFallback className="bg-gradient-to-br from-primary to-primary/70 text-lg font-semibold text-primary-foreground">
              {initials}
            </AvatarFallback>
          </Avatar>

          <div className="flex flex-1 flex-col gap-3">
            <input
              ref={fileInputRef}
              type="file"
              accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
              className="hidden"
              onChange={handleAvatarPick}
            />
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => fileInputRef.current?.click()}
                disabled={uploadAvatarMutation.isPending}
              >
                <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-4 w-4" />
                {avatarUrl ? 'Replace image' : 'Upload image'}
              </Button>
              {avatarDirty && (
                <>
                  <Button
                    type="button"
                    size="sm"
                    onClick={() => avatarFile && uploadAvatarMutation.mutate(avatarFile)}
                    disabled={!avatarFile || uploadAvatarMutation.isPending}
                  >
                    {uploadAvatarMutation.isPending ? (
                      <Icon icon="solar:refresh-bold" className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : (
                      <Icon icon="solar:check-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
                    )}
                    Save avatar
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={handleAvatarCancel}
                    disabled={uploadAvatarMutation.isPending}
                  >
                    Cancel
                  </Button>
                </>
              )}
              {!avatarDirty && avatarUrl && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                  onClick={() => setConfirmRemoveOpen(true)}
                  disabled={removeAvatarMutation.isPending}
                >
                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="mr-1.5 h-4 w-4" />
                  Remove
                </Button>
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              JPG, PNG, GIF or WebP. Up to 10&nbsp;MB.
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:user-bold-duotone" className="h-5 w-5 text-primary" />
            Personal information
          </CardTitle>
          <CardDescription>
            Your name and email appear on activity logs and notifications.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={handleSubmitInfo}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="name">Full name</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => {
                    setName(e.target.value)
                    setInfoDirty(true)
                  }}
                  autoComplete="name"
                  required
                  disabled={isLoading}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="email">Email</Label>
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value)
                    setInfoDirty(true)
                  }}
                  autoComplete="email"
                  required
                  disabled={isLoading}
                />
              </div>
              <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="phone">Phone</Label>
                <Input
                  id="phone"
                  type="tel"
                  value={phone}
                  onChange={(e) => {
                    setPhone(e.target.value)
                    setInfoDirty(true)
                  }}
                  autoComplete="tel"
                  placeholder="Optional"
                  disabled={isLoading}
                />
              </div>
            </div>

            <Separator />

            <div className="flex items-center justify-end gap-2">
              {infoDirty && (
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => {
                    setName(displayName)
                    setEmail(displayEmail)
                    setPhone(displayPhone ?? '')
                    setInfoDirty(false)
                  }}
                  disabled={updateProfileMutation.isPending}
                >
                  Discard
                </Button>
              )}
              <Button
                type="submit"
                disabled={!infoDirty || updateProfileMutation.isPending}
              >
                {updateProfileMutation.isPending ? (
                  <Icon icon="solar:refresh-bold" className="mr-1.5 h-4 w-4 animate-spin" />
                ) : (
                  <Icon icon="solar:diskette-bold-duotone" className="mr-1.5 h-4 w-4" />
                )}
                Save changes
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Dialog open={confirmRemoveOpen} onOpenChange={setConfirmRemoveOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Remove profile picture?</DialogTitle>
            <DialogDescription>
              Your avatar will be deleted and replaced with your initials.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button
              type="button"
              variant="ghost"
              onClick={() => setConfirmRemoveOpen(false)}
              disabled={removeAvatarMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => removeAvatarMutation.mutate()}
              disabled={removeAvatarMutation.isPending}
            >
              {removeAvatarMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="mr-1.5 h-4 w-4 animate-spin" />
              )}
              Remove
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function SecurityTab() {
  const { toast } = useToast()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')

  const changePasswordMutation = useMutation({
    mutationFn: async (payload: { current_password: string; password: string; password_confirmation: string }) => {
      const response = await api.put('/auth/profile/password', payload)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Password updated',
        description:
          'Your password was changed. Other devices signed in to your account have been signed out.',
      })
      setCurrent('')
      setNext('')
      setConfirm('')
    },
    onError: (error: any) => {
      const description = extractErrorMessage(error, 'Failed to update password.')
      toast({ title: 'Update failed', description, variant: 'destructive' })
    },
  })

  const requirementsMet = next.length >= 8 && /[a-z]/.test(next) && /[A-Z]/.test(next) && /\d/.test(next)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (next !== confirm) {
      toast({
        title: 'Passwords do not match',
        description: 'Re-enter the new password in the confirmation field.',
        variant: 'destructive',
      })
      return
    }
    if (!requirementsMet) {
      toast({
        title: 'Password too weak',
        description: 'Use at least 8 characters with upper, lower and a number.',
        variant: 'destructive',
      })
      return
    }
    changePasswordMutation.mutate({
      current_password: current,
      password: next,
      password_confirmation: confirm,
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Icon icon="solar:shield-keyhole-bold-duotone" className="h-5 w-5 text-primary" />
          Change password
        </CardTitle>
        <CardDescription>
          For your security, every other device signed in to your account will be signed out after the change.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="space-y-1.5">
            <Label htmlFor="current-password">Current password</Label>
            <Input
              id="current-password"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              required
            />
          </div>
          <Separator />
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-password">New password</Label>
              <Input
                id="new-password"
                type="password"
                autoComplete="new-password"
                value={next}
                onChange={(e) => setNext(e.target.value)}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="confirm-password">Confirm new password</Label>
              <Input
                id="confirm-password"
                type="password"
                autoComplete="new-password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
              />
            </div>
          </div>

          <ul className="grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
            <li className="flex items-center gap-1.5">
              <Icon
                icon={next.length >= 8 ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}
                className={`h-3.5 w-3.5 ${next.length >= 8 ? 'text-emerald-500' : 'text-muted-foreground/50'}`}
              />
              At least 8 characters
            </li>
            <li className="flex items-center gap-1.5">
              <Icon
                icon={/[a-z]/.test(next) && /[A-Z]/.test(next) ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}
                className={`h-3.5 w-3.5 ${/[a-z]/.test(next) && /[A-Z]/.test(next) ? 'text-emerald-500' : 'text-muted-foreground/50'}`}
              />
              Mixed upper and lower case
            </li>
            <li className="flex items-center gap-1.5">
              <Icon
                icon={/\d/.test(next) ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}
                className={`h-3.5 w-3.5 ${/\d/.test(next) ? 'text-emerald-500' : 'text-muted-foreground/50'}`}
              />
              At least one number
            </li>
            <li className="flex items-center gap-1.5">
              <Icon
                icon={next === confirm && confirm.length > 0 ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}
                className={`h-3.5 w-3.5 ${next === confirm && confirm.length > 0 ? 'text-emerald-500' : 'text-muted-foreground/50'}`}
              />
              Confirmation matches
            </li>
          </ul>

          <Separator />

          <div className="flex items-center justify-end gap-2">
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setCurrent('')
                setNext('')
                setConfirm('')
              }}
              disabled={changePasswordMutation.isPending}
            >
              Clear
            </Button>
            <Button
              type="submit"
              disabled={
                !current ||
                !next ||
                !confirm ||
                changePasswordMutation.isPending
              }
            >
              {changePasswordMutation.isPending ? (
                <Icon icon="solar:refresh-bold" className="mr-1.5 h-4 w-4 animate-spin" />
              ) : (
                <Icon icon="solar:shield-check-bold-duotone" className="mr-1.5 h-4 w-4" />
              )}
              Update password
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

export default ProfilePage
