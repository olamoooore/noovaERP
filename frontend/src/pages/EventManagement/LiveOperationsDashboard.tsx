import React, { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AccessibleButton } from '@/components/ui/AccessibleButton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ResponsiveText, Heading1, BodyText, SmallText } from '@/components/ui/responsive-text';
import { useBreakpoint } from '@/hooks/useBreakpoint';
import { AnimatedMetrics } from '@/components/ui/AnimatedMetrics';
import { EnhancedCard, EnhancedCardContent, EnhancedCardHeader, EnhancedCardTitle } from '@/components/ui/enhanced-card';
import { EnhancedBadge, getSemanticColor } from '@/components/ui/EnhancedBadge';
import { 
  Activity, 
  Users, 
  AlertTriangle, 
  MapPin, 
  Radio,
  TrendingUp,
  Eye,
  RefreshCw,
  Plus,
  CheckCircle,
  AlertCircle,
  Zap,
  Shield,
  Settings
} from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { 
  useEvents,
  useOperationsDashboard,
  useReportIncident,
  useUpdateIncident,
  useSendBroadcast
} from '@/hooks/queries';
import { LoadingSpinner } from '@/components/ui/skeleton';

// Remove unused interfaces - using types from event-management-api.ts instead

const LiveOperationsDashboard: React.FC = () => {
  // UI state only - server data now managed by React Query
  const [selectedEvent, setSelectedEvent] = useState<number | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [showIncidentDialog, setShowIncidentDialog] = useState(false);
  const [showBroadcastDialog, setShowBroadcastDialog] = useState(false);
  const { toast } = useToast();
  const { isMobile } = useBreakpoint();
  
  // React Query hooks for server state
  const { data: events = [], isLoading: eventsLoading } = useEvents();
  const { 
    data: dashboardData
  } = useOperationsDashboard(selectedEvent || 0, {
    refetchInterval: autoRefresh ? 30000 : undefined // 30 seconds if auto-refresh is enabled
  });
  
  // Mutations
  const reportIncidentMutation = useReportIncident();
  const updateIncidentMutation = useUpdateIncident();
  const sendBroadcastMutation = useSendBroadcast();

  // Handle incident reporting with React Query mutation
  const handleIncidentReport = async (incidentData: any) => {
    if (!selectedEvent) return;
    
    try {
      await reportIncidentMutation.mutateAsync({
        ...incidentData,
        event_id: selectedEvent
      });
      
      toast({
        title: "Success",
        description: "Incident reported successfully",
      });
      
      setShowIncidentDialog(false);
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to report incident. Please try again.",
        variant: "destructive",
      });
    }
  };

  // Handle emergency broadcast with React Query mutation
  const handleEmergencyBroadcast = async (message: string) => {
    if (!selectedEvent) return;
    
    try {
      await sendBroadcastMutation.mutateAsync({
        eventId: selectedEvent,
        data: {
          message,
          priority: 'high',
        }
      });
      
      toast({
        title: "Success",
        description: "Emergency broadcast sent successfully",
      });
      
      setShowBroadcastDialog(false);
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to send emergency broadcast. Please try again.",
        variant: "destructive",
      });
    }
  };

  // Handle incident status update with React Query mutation
  const handleIncidentStatusUpdate = async (incidentId: number, action: string) => {
    try {
      await updateIncidentMutation.mutateAsync({
        incidentId,
        action
      });
      
      toast({
        title: "Success",
        description: `Incident ${action} successfully`,
      });
    } catch (error) {
      toast({
        title: "Error",
        description: `Failed to ${action} incident. Please try again.`,
        variant: "destructive",
      });
    }
  };

  if (!selectedEvent) {
    return (
      <div className="container mx-auto p-6">
        <div className="flex justify-between items-center mb-6">
          <div>
            <Heading1 className="text-2xl md:text-3xl">Live Event Operations</Heading1>
            <BodyText variant="muted">Real-time event monitoring and management</BodyText>
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>
              <ResponsiveText size="lg" weight="semibold">Select Event</ResponsiveText>
            </CardTitle>
          </CardHeader>
          <CardContent>
            {eventsLoading ? (
              <div className="flex items-center justify-center p-4">
                <LoadingSpinner size="md" />
              </div>
            ) : (
              <Select onValueChange={(value) => setSelectedEvent(parseInt(value))}>
                <SelectTrigger className="w-full">
                  <SelectValue placeholder="Select an event to monitor" />
                </SelectTrigger>
                <SelectContent>
                  {events.map((event) => (
                    <SelectItem key={event.id} value={event.id.toString()}>
                      <ResponsiveText size="sm">
                        {event.name} - {new Date(event.start_date).toLocaleDateString()}
                      </ResponsiveText>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <Heading1 className="text-2xl md:text-3xl">Live Operations Dashboard</Heading1>
          <BodyText variant="muted">
            {events.find(e => e.id === selectedEvent)?.name} - Real-time monitoring
          </BodyText>
        </div>
        <div className="flex gap-2">
          <AccessibleButton
            variant="outline"
            onClick={() => setAutoRefresh(!autoRefresh)}
            className={autoRefresh ? 'bg-green-50' : ''}
            size={isMobile ? "sm" : "default"}
            ariaLabel={`Auto refresh is ${autoRefresh ? 'on' : 'off'}, click to ${autoRefresh ? 'disable' : 'enable'}`}
            screenReaderText={`Toggle automatic refresh of dashboard data every 30 seconds`}
          >
            <RefreshCw className={`w-4 h-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
            {!isMobile && `Auto Refresh ${autoRefresh ? 'On' : 'Off'}`}
          </AccessibleButton>
          <Dialog open={showBroadcastDialog} onOpenChange={setShowBroadcastDialog}>
            <DialogTrigger asChild>
              <AccessibleButton 
                variant="destructive" 
                className="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 shadow-lg"
                size={isMobile ? "sm" : "default"}
                icon={Radio}
                ariaLabel="Send emergency broadcast"
                screenReaderText="Open dialog to send emergency broadcast message to all event participants"
              >
                <Radio className="w-4 h-4 mr-2" />
                {!isMobile && "Emergency Broadcast"}
              </AccessibleButton>
            </DialogTrigger>
            <DialogContent className="border-red-200 bg-gradient-to-br from-red-50 to-white">
              <DialogHeader>
                <DialogTitle className="flex items-center gap-2 text-red-800">
                  <Radio className="w-5 h-5" />
                  <ResponsiveText size="lg" weight="semibold">Emergency Broadcast</ResponsiveText>
                </DialogTitle>
              </DialogHeader>
              <EmergencyBroadcastForm onSubmit={handleEmergencyBroadcast} />
            </DialogContent>
          </Dialog>
          <Dialog open={showIncidentDialog} onOpenChange={setShowIncidentDialog}>
            <DialogTrigger asChild>
              <AccessibleButton
                size={isMobile ? "sm" : "default"}
                icon={Plus}
                ariaLabel="Report new incident"
                screenReaderText="Open dialog to report a new incident or issue"
              >
                <Plus className="w-4 h-4 mr-2" />
                {!isMobile && "Report Incident"}
              </AccessibleButton>
            </DialogTrigger>
            <DialogContent className="max-w-md">
              <DialogHeader>
                <DialogTitle>
                  <ResponsiveText size="lg" weight="semibold">Report New Incident</ResponsiveText>
                </DialogTitle>
              </DialogHeader>
              <IncidentReportForm onSubmit={handleIncidentReport} />
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* Loading state for operations dashboard */}
      {eventsLoading && (
        <LoadingSpinner />
      )}

      {dashboardData && !eventsLoading && (
        <>
          {/* Key Metrics */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <EnhancedCard variant="elevated" size="sm">
              <EnhancedCardContent>
                <AnimatedMetrics
                  value={dashboardData.metrics.check_ins}
                  previousValue={dashboardData.total_attendees - dashboardData.metrics.check_ins}
                  format="number"
                  label="Check-in Progress"
                  icon={Activity}
                  size="md"
                />
                <div className="mt-2">
                  <SmallText variant="muted">
                    {dashboardData.total_attendees > 0 ? ((dashboardData.metrics.check_ins / dashboardData.total_attendees) * 100).toFixed(1) : 0}% of {dashboardData.total_attendees} attendees
                  </SmallText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>

            <EnhancedCard variant="elevated" size="sm">
              <EnhancedCardContent>
                <AnimatedMetrics
                  value={dashboardData.metrics.active_sessions}
                  format="number"
                  label="Active Sessions"
                  icon={Users}
                  size="md"
                />
                <div className="mt-2">
                  <SmallText variant="muted">Currently active</SmallText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>

            <EnhancedCard variant="elevated" size="sm">
              <EnhancedCardContent>
                <AnimatedMetrics
                  value={dashboardData.active_staff}
                  format="number"
                  label="Active Staff"
                  icon={Users}
                  size="md"
                />
                <div className="mt-2">
                  <SmallText variant="muted">Staff on duty</SmallText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>

            <EnhancedCard variant="elevated" size="sm">
              <EnhancedCardContent>
                <AnimatedMetrics
                  value={dashboardData.incidents.filter(i => ['open', 'in_progress'].includes(i.status)).length}
                  format="number"
                  label="Open Incidents"
                  icon={AlertTriangle}
                  size="md"
                />
                <div className="mt-2">
                  <SmallText variant="muted">Require attention</SmallText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>
          </div>

          {/* Operational Status */}
          <EnhancedCard variant="elevated">
            <EnhancedCardHeader>
              <EnhancedCardTitle className="flex items-center gap-2">
                <Eye className="w-5 h-5" />
                Current Operational Status
              </EnhancedCardTitle>
            </EnhancedCardHeader>
            <EnhancedCardContent>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div className="text-center space-y-2">
                  <div className="flex items-center justify-center">
                    <Activity className="w-5 h-5 text-gray-500 mr-2" />
                    <ResponsiveText size="sm" weight="medium" variant="muted">Event Status</ResponsiveText>
                  </div>
                  <EnhancedBadge 
                    variant="status" 
                    color={getSemanticColor(dashboardData.event.status, 'status')}
                    size="md"
                  >
                    {dashboardData.event.status}
                  </EnhancedBadge>
                </div>
                <div className="text-center space-y-2">
                  <div className="flex items-center justify-center">
                    <Users className="w-5 h-5 text-gray-500 mr-2" />
                    <ResponsiveText size="sm" weight="medium" variant="muted">Capacity</ResponsiveText>
                  </div>
                  <EnhancedBadge 
                    variant="status" 
                    color={getSemanticColor('normal', 'status')}
                    size="md"
                  >
                    Normal
                  </EnhancedBadge>
                </div>
                <div className="text-center space-y-2">
                  <div className="flex items-center justify-center">
                    <Shield className="w-5 h-5 text-gray-500 mr-2" />
                    <ResponsiveText size="sm" weight="medium" variant="muted">Security</ResponsiveText>
                  </div>
                  <EnhancedBadge 
                    variant="status" 
                    color={getSemanticColor('normal', 'status')}
                    size="md"
                  >
                    Normal
                  </EnhancedBadge>
                </div>
                <div className="text-center space-y-2">
                  <div className="flex items-center justify-center">
                    <Settings className="w-5 h-5 text-gray-500 mr-2" />
                    <ResponsiveText size="sm" weight="medium" variant="muted">Technical</ResponsiveText>
                  </div>
                  <EnhancedBadge 
                    variant="status" 
                    color={getSemanticColor('normal', 'status')}
                    size="md"
                  >
                    Normal
                  </EnhancedBadge>
                </div>
              </div>
            </EnhancedCardContent>
          </EnhancedCard>

          <Tabs defaultValue="zones" className="space-y-4">
            <TabsList>
              <TabsTrigger value="zones">Zone Status</TabsTrigger>
              <TabsTrigger value="staff">Staff Overview</TabsTrigger>
              <TabsTrigger value="queues">Queue Management</TabsTrigger>
              <TabsTrigger value="incidents">Incident Tracking</TabsTrigger>
            </TabsList>

            <TabsContent value="zones" className="space-y-4">
              <EnhancedCard variant="elevated">
                <EnhancedCardHeader>
                  <EnhancedCardTitle className="flex items-center gap-2">
                    <MapPin className="w-5 h-5" />
                    Zone Occupancy & Status
                  </EnhancedCardTitle>
                </EnhancedCardHeader>
                <EnhancedCardContent>
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {/* Placeholder zone data - replace with actual zone data when available */}
                    {[
                      {
                        id: 1,
                        zone_name: 'Main Hall',
                        current_occupancy: 150,
                        max_capacity: 200,
                        occupancy_percentage: 75,
                        status: 'normal'
                      }
                    ].map((zone) => (
                      <EnhancedCard key={zone.id} variant="outlined" size="sm" className="border-green-300 bg-green-50/50">
                        <EnhancedCardHeader className="pb-2">
                          <div className="flex justify-between items-center">
                            <EnhancedCardTitle className="text-lg">{zone.zone_name}</EnhancedCardTitle>
                            <EnhancedBadge 
                              variant="status" 
                              color={getSemanticColor(zone.status, 'status')}
                              size="sm"
                            >
                              Normal
                            </EnhancedBadge>
                          </div>
                        </EnhancedCardHeader>
                        <EnhancedCardContent>
                          <div className="space-y-3">
                            <div className="flex justify-between text-sm">
                                <span className="font-medium">Occupancy:</span>
                                <span className="font-bold">
                                  {zone.current_occupancy} / {zone.max_capacity}
                                </span>
                              </div>
                              
                              {/* Enhanced Progress Bar with Heat Map Colors */}
                              <div className="relative">
                                <div className="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                  <div
                                    className={`h-3 rounded-full transition-all duration-500 ease-out ${
                                      zone.occupancy_percentage >= 90 ? 'bg-gradient-to-r from-red-500 to-red-600' :
                                      zone.occupancy_percentage >= 70 ? 'bg-gradient-to-r from-yellow-500 to-yellow-600' : 
                                      'bg-gradient-to-r from-green-500 to-green-600'
                                    }`}
                                    style={{ width: `${Math.min(zone.occupancy_percentage, 100)}%` }}
                                  />
                                </div>
                                {/* Capacity indicator overlay */}
                                <div className="absolute inset-0 flex items-center justify-center">
                                  <span className="text-xs font-bold text-white drop-shadow-sm">
                                    {zone.occupancy_percentage.toFixed(1)}%
                                  </span>
                                </div>
                              </div>
                              
                              {/* Heat Map Indicator */}
                              <div className="flex items-center justify-between text-xs">
                                <span className="text-gray-600">Capacity Level</span>
                                <div className="flex items-center gap-1">
                                  <div className={`w-2 h-2 rounded-full ${
                                    zone.occupancy_percentage >= 90 ? 'bg-red-500' :
                                    zone.occupancy_percentage >= 70 ? 'bg-yellow-500' : 
                                    'bg-green-500'
                                  }`} />
                                  <span className={`font-medium ${
                                    zone.occupancy_percentage >= 90 ? 'text-red-700' :
                                    zone.occupancy_percentage >= 70 ? 'text-yellow-700' : 
                                    'text-green-700'
                                  }`}>
                                    {zone.occupancy_percentage >= 90 ? 'High' :
                                     zone.occupancy_percentage >= 70 ? 'Medium' : 'Low'}
                                  </span>
                                </div>
                              </div>
                            </div>
                          </EnhancedCardContent>
                        </EnhancedCard>
                    ))}
                  </div>
                </EnhancedCardContent>
              </EnhancedCard>
            </TabsContent>

            <TabsContent value="staff" className="space-y-4">
              <EnhancedCard variant="elevated">
                <EnhancedCardHeader>
                  <EnhancedCardTitle className="flex items-center gap-2">
                    <Users className="w-5 h-5" />
                    Staff Status by Role
                  </EnhancedCardTitle>
                </EnhancedCardHeader>
                <EnhancedCardContent>
                  <div className="space-y-6">
                    <div>
                      <h4 className="font-semibold mb-3 capitalize text-gray-800 flex items-center gap-2">
                        <Users className="w-4 h-4" />
                        Active Staff ({dashboardData.active_staff})
                      </h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <EnhancedCard variant="outlined" size="sm" className="hover:shadow-md">
                          <EnhancedCardContent className="flex items-center justify-between">
                            <div>
                              <div className="font-medium text-sm">Staff Member</div>
                              <div className="text-xs text-gray-500 flex items-center gap-1">
                                <MapPin className="w-3 h-3" />
                                Main Area
                              </div>
                            </div>
                            <EnhancedBadge 
                              variant="status" 
                              color={getSemanticColor('active', 'status')}
                              size="sm"
                            >
                              Active
                            </EnhancedBadge>
                          </EnhancedCardContent>
                        </EnhancedCard>
                      </div>
                    </div>
                  </div>
                </EnhancedCardContent>
              </EnhancedCard>
            </TabsContent>

            <TabsContent value="queues" className="space-y-4">
              <EnhancedCard variant="elevated">
                <EnhancedCardHeader>
                  <EnhancedCardTitle className="flex items-center gap-2">
                    <TrendingUp className="w-5 h-5" />
                    Queue Management
                  </EnhancedCardTitle>
                </EnhancedCardHeader>
                <EnhancedCardContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Counter</TableHead>
                        <TableHead>Queue Length</TableHead>
                        <TableHead>Processing Rate</TableHead>
                        <TableHead>Est. Wait Time</TableHead>
                        <TableHead>Status</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <TableRow>
                        <TableCell className="font-medium">Main Counter</TableCell>
                        <TableCell>
                          <div className="flex items-center gap-2">
                            <span className="font-semibold">5</span>
                            <div className="w-2 h-2 rounded-full bg-green-500" />
                          </div>
                        </TableCell>
                        <TableCell>3/min</TableCell>
                        <TableCell>2 min</TableCell>
                        <TableCell>
                          <EnhancedBadge 
                            variant="status"
                            color="success"
                            size="sm"
                            >
                              Normal
                            </EnhancedBadge>
                          </TableCell>
                        </TableRow>
                    </TableBody>
                  </Table>
                </EnhancedCardContent>
              </EnhancedCard>
            </TabsContent>

            <TabsContent value="incidents" className="space-y-4">
              <EnhancedCard variant="elevated">
                <EnhancedCardHeader>
                  <EnhancedCardTitle className="flex items-center gap-2">
                    <AlertTriangle className="w-5 h-5" />
                    Recent Incidents
                  </EnhancedCardTitle>
                </EnhancedCardHeader>
                <EnhancedCardContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Type</TableHead>
                        <TableHead>Title</TableHead>
                        <TableHead>Severity</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Zone</TableHead>
                        <TableHead>Reported</TableHead>
                        <TableHead>Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {dashboardData.incidents.map((incident) => (
                        <TableRow key={incident.id}>
                          <TableCell className="capitalize">Security</TableCell>
                          <TableCell className="font-medium">{incident.title}</TableCell>
                          <TableCell>
                            <EnhancedBadge 
                              variant="priority" 
                              color={getSemanticColor(incident.severity, 'priority')}
                              size="sm"
                              icon={incident.severity === 'critical' ? Zap : undefined}
                              pulse={incident.severity === 'critical'}
                            >
                              {incident.severity}
                            </EnhancedBadge>
                          </TableCell>
                          <TableCell>
                            <EnhancedBadge 
                              variant="status" 
                              color={getSemanticColor(incident.status, 'status')}
                              size="sm"
                            >
                              {incident.status}
                            </EnhancedBadge>
                          </TableCell>
                          <TableCell>N/A</TableCell>
                          <TableCell>{new Date(incident.reported_at).toLocaleTimeString()}</TableCell>
                          <TableCell>
                            <div className="flex gap-1">
                              {incident.status === 'open' && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => handleIncidentStatusUpdate(incident.id, 'acknowledge')}
                                >
                                  <CheckCircle className="w-3 h-3" />
                                </Button>
                              )}
                              {['open', 'in_progress'].includes(incident.status) && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => handleIncidentStatusUpdate(incident.id, 'escalate')}
                                >
                                  <AlertCircle className="w-3 h-3" />
                                </Button>
                              )}
                            </div>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </EnhancedCardContent>
              </EnhancedCard>
            </TabsContent>
          </Tabs>
        </>
      )}
    </div>
  );
};

// Incident Report Form Component
const IncidentReportForm: React.FC<{
  onSubmit: (data: any) => void;
}> = ({ onSubmit }) => {
  const [formData, setFormData] = useState({
    incident_type: '',
    severity: '',
    title: '',
    description: '',
    zone_id: '',
    people_affected: 0
  });

  const incidentTypes = [
    { value: 'security', label: 'Security' },
    { value: 'medical', label: 'Medical' },
    { value: 'technical', label: 'Technical' },
    { value: 'crowd_control', label: 'Crowd Control' },
    { value: 'weather', label: 'Weather' },
    { value: 'equipment_failure', label: 'Equipment Failure' },
    { value: 'staff_issue', label: 'Staff Issue' },
    { value: 'customer_complaint', label: 'Customer Complaint' },
    { value: 'emergency', label: 'Emergency' },
    { value: 'other', label: 'Other' }
  ];

  const severityLevels = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' }
  ];

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="incident_type">Incident Type</Label>
        <Select value={formData.incident_type} onValueChange={(value) => setFormData({ ...formData, incident_type: value })}>
          <SelectTrigger>
            <SelectValue placeholder="Select incident type" />
          </SelectTrigger>
          <SelectContent>
            {incidentTypes.map((type) => (
              <SelectItem key={type.value} value={type.value}>
                {type.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div>
        <Label htmlFor="severity">Severity</Label>
        <Select value={formData.severity} onValueChange={(value) => setFormData({ ...formData, severity: value })}>
          <SelectTrigger>
            <SelectValue placeholder="Select severity" />
          </SelectTrigger>
          <SelectContent>
            {severityLevels.map((level) => (
              <SelectItem key={level.value} value={level.value}>
                {level.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div>
        <Label htmlFor="title">Title</Label>
        <Input
          id="title"
          value={formData.title}
          onChange={(e) => setFormData({ ...formData, title: e.target.value })}
          required
        />
      </div>

      <div>
        <Label htmlFor="description">Description</Label>
        <Textarea
          id="description"
          value={formData.description}
          onChange={(e) => setFormData({ ...formData, description: e.target.value })}
          required
        />
      </div>

      <div>
        <Label htmlFor="people_affected">People Affected</Label>
        <Input
          id="people_affected"
          type="number"
          min="0"
          value={formData.people_affected}
          onChange={(e) => setFormData({ ...formData, people_affected: parseInt(e.target.value) || 0 })}
        />
      </div>

      <Button type="submit" className="w-full">
        Report Incident
      </Button>
    </form>
  );
};

// Emergency Broadcast Form Component
const EmergencyBroadcastForm: React.FC<{
  onSubmit: (message: string) => void;
}> = ({ onSubmit }) => {
  const [message, setMessage] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(message);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="message">Emergency Message</Label>
        <Textarea
          id="message"
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          placeholder="Enter emergency broadcast message..."
          required
          className="min-h-[100px]"
        />
      </div>
      <div className="flex gap-2">
        <Button type="submit" variant="destructive" className="flex-1">
          <Radio className="w-4 h-4 mr-2" />
          Send Emergency Broadcast
        </Button>
      </div>
    </form>
  );
};

export default LiveOperationsDashboard;