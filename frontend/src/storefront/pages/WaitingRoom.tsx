import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Hourglass, RefreshCw } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import {
  getCheckoutQueueStatus,
  heartbeatCheckoutQueue,
  joinCheckoutQueue,
  leaveCheckoutQueue,
  persistCheckoutQueueContext,
  resolveCheckoutQueueContext,
  type QueueStatusResponse,
} from '@/lib/checkout-queue';
import { storefrontRoutes } from '@/storefront/lib/paths';

function buildCheckoutUrl(returnTo: string, campaignKey: string, queueToken: string): string {
  const target = new URL(returnTo, window.location.origin);
  target.searchParams.set('queue_required', '1');
  target.searchParams.set('campaign_key', campaignKey);
  target.searchParams.set('queue_token', queueToken);

  return `${target.pathname}${target.search}`;
}

export default function WaitingRoom() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const storedQueue = useMemo(() => resolveCheckoutQueueContext(), []);

  const returnTo = searchParams.get('return_to') || storefrontRoutes.checkout;
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
      // Ignore bootstrap failures.
    });
    // Intentionally run once for initial queue context.
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
      toast.error('Campaign key is required to join queue.');
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

      toast.success('Joined waiting room. Keep this page open while we hold your slot.');
    } catch (error: any) {
      toast.error(error?.response?.data?.message || 'Unable to join queue right now.');
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
      toast.error(error?.response?.data?.message || 'Unable to refresh queue status.');
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
      toast.success('Left queue successfully.');
    } catch {
      toast.error('Unable to leave queue right now.');
    }
  };

  return (
    <Layout>
      <div className="container py-10 md:py-14 max-w-3xl">
        <Card className="glass-strong border-primary/20">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Hourglass className="h-5 w-5 text-primary" />
              Waiting Room
            </CardTitle>
            <CardDescription>
              High-demand release is currently queued. Join and wait for your active checkout slot.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
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
              <Button variant="hero" onClick={joinQueue} disabled={isJoining}>
                {isJoining ? 'Joining...' : 'Join Queue'}
              </Button>
              <Button variant="outline" onClick={refreshStatus} disabled={!queueToken || isRefreshing}>
                {isRefreshing ? 'Refreshing...' : (
                  <>
                    <RefreshCw className="h-4 w-4 mr-2" />
                    Refresh
                  </>
                )}
              </Button>
              <Button variant="ghost" onClick={leaveQueue} disabled={!queueToken}>
                Leave Queue
              </Button>
            </div>

            {status ? (
              <div className="rounded-xl border border-border p-4 space-y-3 bg-background/50">
                <div className="flex items-center justify-between">
                  <span className="text-sm text-muted-foreground">Status</span>
                  <Badge variant={status.status === 'active' ? 'default' : 'secondary'}>
                    {status.status === 'active' ? 'Active Slot' : 'Waiting'}
                  </Badge>
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Position</p>
                    <p className="text-2xl font-bold">{status.position}</p>
                  </div>
                  <div>
                    <p className="text-sm text-muted-foreground">Est. Wait</p>
                    <p className="text-2xl font-bold">{status.estimated_wait_seconds}s</p>
                  </div>
                </div>
                <p className="text-xs text-muted-foreground">
                  Queue token expires at {new Date(status.expires_at).toLocaleString()}.
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                Join the queue to receive your live position and slot updates.
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </Layout>
  );
}
