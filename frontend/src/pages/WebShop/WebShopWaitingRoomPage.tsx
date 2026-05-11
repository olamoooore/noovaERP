import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import {
  getCheckoutQueueStatus,
  heartbeatCheckoutQueue,
  joinCheckoutQueue,
  leaveCheckoutQueue,
  persistCheckoutQueueContext,
  resolveCheckoutQueueContext,
  type QueueStatusResponse,
} from '@/lib/checkout-queue';

function buildCheckoutUrl(returnTo: string, campaignKey: string, queueToken: string): string {
  const target = new URL(returnTo, window.location.origin);
  target.searchParams.set('queue_required', '1');
  target.searchParams.set('campaign_key', campaignKey);
  target.searchParams.set('queue_token', queueToken);

  return `${target.pathname}${target.search}`;
}

export function WebShopWaitingRoomPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { toast } = useToast();

  const storedQueue = useMemo(() => resolveCheckoutQueueContext(), []);

  const returnTo = searchParams.get('return_to') || '/web-shop/checkout';
  const [campaignKey, setCampaignKey] = useState(
    searchParams.get('campaign_key') || storedQueue?.campaign_key || '',
  );
  const [queueToken, setQueueToken] = useState(
    searchParams.get('queue_token') || storedQueue?.queue_token || '',
  );
  const [status, setStatus] = useState<QueueStatusResponse | null>(null);
  const [isJoining, setIsJoining] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);

  const syncStatus = async (nextCampaignKey: string, nextQueueToken: string, heartbeat = false) => {
    const result = heartbeat
      ? await heartbeatCheckoutQueue(nextCampaignKey, nextQueueToken)
      : await getCheckoutQueueStatus(nextCampaignKey, nextQueueToken);

    setStatus(result);
    persistCheckoutQueueContext({
      queue_required: true,
      campaign_key: nextCampaignKey,
      queue_token: nextQueueToken,
    });

    if (result.status === 'active') {
      navigate(buildCheckoutUrl(returnTo, nextCampaignKey, nextQueueToken), { replace: true });
    }
  };

  useEffect(() => {
    if (!campaignKey || !queueToken) {
      return;
    }

    syncStatus(campaignKey, queueToken).catch(() => {
      // Ignore bootstrap errors to allow rejoin.
    });
    // Intentionally run on initial queue bootstrap values.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!campaignKey || !queueToken) {
      return;
    }

    const intervalId = window.setInterval(() => {
      syncStatus(campaignKey, queueToken, true).catch(() => {
        // Best effort heartbeat.
      });
    }, 15000);

    return () => {
      window.clearInterval(intervalId);
    };
  }, [campaignKey, queueToken]);

  const joinQueue = async () => {
    if (!campaignKey.trim()) {
      toast({
        variant: 'destructive',
        title: 'Campaign key required',
        description: 'Please enter a campaign key to join the queue.',
      });
      return;
    }

    setIsJoining(true);
    try {
      const joinResult = await joinCheckoutQueue(campaignKey.trim());
      setCampaignKey(joinResult.campaign_key);
      setQueueToken(joinResult.queue_token);
      setStatus(joinResult);

      if (joinResult.status === 'active') {
        navigate(buildCheckoutUrl(returnTo, joinResult.campaign_key, joinResult.queue_token), { replace: true });
        return;
      }

      toast({
        title: 'Joined waiting room',
        description: 'You are now in the queue. Keep this page open while we hold your slot.',
      });
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Unable to join queue',
        description: error?.response?.data?.message || 'Please try again in a moment.',
      });
    } finally {
      setIsJoining(false);
    }
  };

  const refreshStatus = async () => {
    if (!campaignKey || !queueToken) {
      return;
    }

    setIsRefreshing(true);
    try {
      await syncStatus(campaignKey, queueToken, true);
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Unable to refresh queue status',
        description: error?.response?.data?.message || 'Please try again.',
      });
    } finally {
      setIsRefreshing(false);
    }
  };

  const leaveQueue = async () => {
    if (!campaignKey || !queueToken) {
      return;
    }

    try {
      await leaveCheckoutQueue(campaignKey, queueToken);
      setQueueToken('');
      setStatus(null);
      toast({ title: 'Left queue', description: 'You can rejoin anytime.' });
    } catch {
      toast({
        variant: 'destructive',
        title: 'Unable to leave queue',
        description: 'Please try again.',
      });
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-950">
      <div className="mx-auto max-w-3xl px-4 py-12">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:hourglass-line-duotone" className="h-5 w-5" />
              Checkout Waiting Room
            </CardTitle>
            <CardDescription>
              Join the queue to secure checkout admission for high-demand campaigns.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="campaignKey">Campaign Key</Label>
              <Input
                id="campaignKey"
                placeholder="e.g. infiniti-kpop-launch"
                value={campaignKey}
                onChange={(event) => setCampaignKey(event.target.value)}
              />
            </div>

            <div className="flex flex-wrap gap-2">
              <Button onClick={joinQueue} disabled={isJoining}>
                {isJoining ? 'Joining...' : 'Join Queue'}
              </Button>
              <Button variant="outline" onClick={refreshStatus} disabled={!queueToken || isRefreshing}>
                {isRefreshing ? 'Refreshing...' : 'Refresh Status'}
              </Button>
              <Button variant="ghost" onClick={leaveQueue} disabled={!queueToken}>
                Leave Queue
              </Button>
            </div>

            {status ? (
              <Card className="border-dashed">
                <CardContent className="pt-6 space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">Status</span>
                    <Badge variant={status.status === 'active' ? 'default' : 'secondary'}>
                      {status.status === 'active' ? 'Active Slot' : 'Waiting'}
                    </Badge>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                      <p className="text-sm text-muted-foreground">Position</p>
                      <p className="text-xl font-semibold">{status.position}</p>
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">Est. Wait</p>
                      <p className="text-xl font-semibold">{status.estimated_wait_seconds}s</p>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Token expires at {new Date(status.expires_at).toLocaleString()}.
                  </p>
                </CardContent>
              </Card>
            ) : (
              <p className="text-sm text-muted-foreground">
                Join the queue to receive your position and slot status.
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

export default WebShopWaitingRoomPage;
