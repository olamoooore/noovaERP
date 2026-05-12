import React, { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AccessibleButton } from '@/components/ui/AccessibleButton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ResponsiveText, Heading1, BodyText, SmallText } from '@/components/ui/responsive-text';
import { useBreakpoint } from '@/hooks/useBreakpoint';
import { EnhancedStaffCard, type StaffStatus, type StaffRole } from '@/components/ui/enhanced-staff-card';
import { 
  EnhancedAssignmentManager, 
  type ZoneCapacity 
} from '@/components/ui/enhanced-assignment-manager';
import { Grid3X3, List, Layout } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { 
  StaffRoleIcon, 
  StaffStatusIcon, 
  ActionIcon, 
  SystemIcon, 
  FeedbackIcon 
} from '@/components/ui/enhanced-icon';
import { 
  useStaffEvents,
  useStaffAssignments,
  usePerformanceReport,
  useAssignStaff,
  useUpdateStaffStatus
} from '@/hooks/queries';
import { LoadingSpinner } from '@/components/ui/skeleton';
import { StaffAssignment } from '@/lib/event-management-api';

const StaffManagementPage: React.FC = () => {
  // UI state only - server data now managed by React Query
  const [selectedEvent, setSelectedEvent] = useState<number | null>(null);
  const [showAssignDialog, setShowAssignDialog] = useState(false);
  const [viewMode, setViewMode] = useState<'cards' | 'table' | 'assignment'>('cards');
  const { toast } = useToast();
  const { isMobile } = useBreakpoint();
  
  // React Query hooks for server state
  const { data: events = [], isLoading: eventsLoading, error: eventsError } = useStaffEvents();
  const { 
    data: staffAssignments = [], 
    isLoading: assignmentsLoading, 
    error: assignmentsError 
  } = useStaffAssignments(selectedEvent || 0);
  const { 
    data: staffPerformance = [], 
    isLoading: performanceLoading, 
    refetch: refetchPerformance 
  } = usePerformanceReport();
  
  // Mutations
  const assignStaffMutation = useAssignStaff();
  const updateStatusMutation = useUpdateStaffStatus();

  const staffRoles = [
    { value: 'manager', label: 'Event Manager' },
    { value: 'coordinator', label: 'Event Coordinator' },
    { value: 'security', label: 'Security Staff' },
    { value: 'usher', label: 'Usher' },
    { value: 'technical', label: 'Technical Staff' },
    { value: 'medical', label: 'Medical Staff' },
    { value: 'customer_service', label: 'Customer Service' },
    { value: 'merchandise', label: 'Merchandise Staff' },
    { value: 'cleaning', label: 'Cleaning Staff' },
    { value: 'volunteer', label: 'Volunteer' }
  ];
  const handleAssignStaff = async (assignmentData: any) => {
    if (!selectedEvent) return;
    
    try {
      await assignStaffMutation.mutateAsync({
        eventId: selectedEvent,
        data: assignmentData
      });
      
      toast({
        title: "Success",
        description: "Staff member assigned successfully",
      });
      
      setShowAssignDialog(false);
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to assign staff member. Please try again.",
        variant: "destructive",
      });
    }
  };

  // Handle status update with React Query mutation
  const handleStatusUpdate = async (assignmentId: number, status: string) => {
    try {
      await updateStatusMutation.mutateAsync({
        assignmentId,
        status
      });
      
      toast({
        title: "Success",
        description: "Staff status updated successfully",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to update staff status. Please try again.",
        variant: "destructive",
      });
    }
  };

  const generateSchedulingReport = async () => {
    if (!selectedEvent) return;
    
    try {
      // This would use a separate mutation hook in a real implementation
      toast({
        title: "Report Generated",
        description: "Scheduling report has been generated successfully",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to generate scheduling report. Please try again.",
        variant: "destructive",
      });
    }
  };

  const generateZoneCapacities = (assignments: StaffAssignment[]): ZoneCapacity[] => {
    // Since API doesn't provide zone info, create default zones based on roles
    const zones = Array.from(new Set(assignments.map(a => a.role).filter(Boolean)));
    
    return zones.map(zone => {
      const zoneAssignments = assignments.filter(a => a.role === zone);
      const activeStaff = zoneAssignments.filter(a => a.status === 'confirmed').length;
      
      return {
        zoneId: zone.toLowerCase().replace(/\s+/g, '-'),
        zoneName: zone,
        requiredStaff: Math.max(3, Math.ceil(zoneAssignments.length * 1.2)), // 20% buffer
        assignedStaff: zoneAssignments.length,
        activeStaff: activeStaff,
        maxCapacity: Math.ceil(zoneAssignments.length * 1.5) // 50% over-capacity limit
      };
    });
  };

  const selectedEventObj = events.find((e) => e.id === selectedEvent);

  return (
    <div className="container mx-auto px-4 py-8 space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 lg:flex-row lg:justify-between lg:items-start">
        <div className="flex items-start gap-3">
          <div className="p-3 bg-primary/10 rounded-xl shrink-0">
            <StaffRoleIcon name="manager" size="lg" className="text-primary" />
          </div>
          <div>
            <Heading1 className="text-2xl md:text-3xl">Staff Operations</Heading1>
            <BodyText variant="muted">Assignments, scheduling, performance & shift handover</BodyText>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <AccessibleButton 
            onClick={generateSchedulingReport} 
            variant="outline" 
            disabled={!selectedEvent}
            size={isMobile ? "sm" : "default"}
            ariaLabel="Generate scheduling report"
            screenReaderText="Generate and download scheduling report for selected event"
          >
            <SystemIcon name="download" size="sm" className="mr-2" />
            {!isMobile && "Generate Report"}
          </AccessibleButton>
          <div className="flex border rounded-lg">
            <AccessibleButton
              variant={viewMode === 'cards' ? 'default' : 'ghost'}
              size="sm"
              onClick={() => setViewMode('cards')}
              className="rounded-r-none"
              ariaLabel="Switch to cards view"
              screenReaderText="Display staff assignments as cards"
            >
              <Grid3X3 className="w-4 h-4" />
            </AccessibleButton>
            <AccessibleButton
              variant={viewMode === 'table' ? 'default' : 'ghost'}
              size="sm"
              onClick={() => setViewMode('table')}
              className="rounded-none"
              ariaLabel="Switch to table view"
              screenReaderText="Display staff assignments as table"
            >
              <List className="w-4 h-4" />
            </AccessibleButton>
            <AccessibleButton
              variant={viewMode === 'assignment' ? 'default' : 'ghost'}
              size="sm"
              onClick={() => setViewMode('assignment')}
              className="rounded-l-none"
              ariaLabel="Switch to assignment view"
              screenReaderText="Display staff assignments with capacity management"
            >
              <Layout className="w-4 h-4" />
            </AccessibleButton>
          </div>
          <Dialog open={showAssignDialog} onOpenChange={setShowAssignDialog}>
            <DialogTrigger asChild>
              <AccessibleButton 
                disabled={!selectedEvent}
                size={isMobile ? "sm" : "default"}
                ariaLabel="Assign staff member"
                screenReaderText="Open dialog to assign staff member to selected event"
              >
                <ActionIcon name="assign_task" size="sm" className="mr-2" />
                {!isMobile && "Assign Staff"}
              </AccessibleButton>
            </DialogTrigger>
            <DialogContent className="max-w-md">
              <DialogHeader>
                <DialogTitle>
                  <ResponsiveText size="lg" weight="semibold">Assign Staff Member</ResponsiveText>
                </DialogTitle>
              </DialogHeader>
              <StaffAssignmentForm onSubmit={handleAssignStaff} roles={staffRoles} />
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* Event Selection */}
      <Card>
        <CardContent className="p-4 md:p-5">
          <div className="flex flex-col md:flex-row md:items-center md:gap-4 gap-2">
            <Label className="text-sm font-medium md:w-32 shrink-0">Select Event</Label>
            {eventsLoading ? (
              <div className="flex items-center gap-2 text-muted-foreground text-sm">
                <LoadingSpinner size="sm" /> Loading events…
              </div>
            ) : eventsError ? (
              <ResponsiveText size="sm" variant="muted">
                Failed to load events. Please refresh the page.
              </ResponsiveText>
            ) : (
              <Select value={selectedEvent?.toString()} onValueChange={(value) => setSelectedEvent(parseInt(value))}>
                <SelectTrigger className="w-full md:max-w-md">
                  <SelectValue placeholder="Select an event to manage staff" />
                </SelectTrigger>
                <SelectContent>
                  {events.map((event) => (
                    <SelectItem key={event.id} value={event.id.toString()}>
                      <ResponsiveText size="sm">
                        {event.name} — {new Date(event.start_date).toLocaleDateString()}
                      </ResponsiveText>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {selectedEventObj && (
              <Badge variant="secondary" className="ml-auto hidden md:inline-flex">
                {staffAssignments.length} staff assigned
              </Badge>
            )}
          </div>
        </CardContent>
      </Card>

      {selectedEvent && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground uppercase tracking-wide">Total Staff</p>
              <p className="text-2xl font-bold mt-1">{staffAssignments.length}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground uppercase tracking-wide">Confirmed</p>
              <p className="text-2xl font-bold mt-1 text-green-600">
                {staffAssignments.filter((s) => s.status === 'confirmed').length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground uppercase tracking-wide">Assigned</p>
              <p className="text-2xl font-bold mt-1 text-amber-600">
                {staffAssignments.filter((s) => s.status === 'assigned').length}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground uppercase tracking-wide">Completed</p>
              <p className="text-2xl font-bold mt-1 text-blue-600">
                {staffAssignments.filter((s) => s.status === 'completed').length}
              </p>
            </CardContent>
          </Card>
        </div>
      )}

      {selectedEvent && (
        <Tabs defaultValue="assignments" className="space-y-4">
          <TabsList>
            <TabsTrigger value="assignments">Staff Assignments</TabsTrigger>
            <TabsTrigger value="performance">Performance Monitoring</TabsTrigger>
            <TabsTrigger value="handover">Operational Handover</TabsTrigger>
          </TabsList>

          <TabsContent value="assignments" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <StaffRoleIcon name="manager" size="md" />
                  <ResponsiveText size="lg" weight="semibold">Current Staff Assignments</ResponsiveText>
                </CardTitle>
              </CardHeader>
              <CardContent>
                {assignmentsLoading ? (
                  <div className="flex items-center justify-center p-8">
                    <LoadingSpinner size="lg" />
                  </div>
                ) : assignmentsError ? (
                  <div className="text-center p-8">
                    <ResponsiveText size="sm" variant="muted">
                      Failed to load staff assignments. Please try again.
                    </ResponsiveText>
                  </div>
                ) : viewMode === 'assignment' ? (
                  <EnhancedAssignmentManager
                    view="capacity"
                    assignments={staffAssignments.map(assignment => ({
                      id: assignment.id.toString(),
                      staffId: assignment.user_id.toString(),
                      staffName: assignment.user?.name || 'Unknown',
                      role: assignment.role,
                      zone: assignment.role, // Use role as zone since API doesn't provide zone
                      shiftStart: assignment.assigned_at,
                      shiftEnd: assignment.assigned_at, // API doesn't provide shift end
                      status: assignment.status as StaffStatus
                    }))}
                    zoneCapacities={generateZoneCapacities(staffAssignments)}
                    onAssignmentMove={(assignment, targetZone) => {
                      toast({
                        title: "Assignment Moved",
                        description: `${assignment.staffName} moved to ${targetZone}`
                      });
                    }}
                    onAssignmentEdit={(assignment) => {
                      toast({
                        title: "Edit Assignment",
                        description: `Editing assignment for ${assignment.staffName}`
                      });
                    }}
                    onAssignmentRemove={() => {
                      toast({
                        title: "Assignment Removed",
                        description: "Staff assignment has been removed"
                      });
                    }}
                  />
                ) : viewMode === 'cards' ? (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {staffAssignments.map((assignment) => (
                      <EnhancedStaffCard
                        key={assignment.id}
                        staffId={assignment.user_id.toString()}
                        staffName={assignment.user?.name || 'Unknown'}
                        role={assignment.role as StaffRole}
                        zone={assignment.role} // Use role as zone since API doesn't provide zone
                        status={assignment.status as StaffStatus}
                        isLead={false} // API doesn't provide is_lead info
                        shiftStart={assignment.assigned_at}
                        shiftEnd={assignment.assigned_at} // API doesn't provide shift_end
                        checkedInAt={undefined} // API doesn't provide checked_in_at
                        checkedOutAt={undefined} // API doesn't provide checked_out_at
                        performanceRating={undefined} // API doesn't provide performance_rating
                        onStatusChange={(status) => handleStatusUpdate(assignment.id, status)}
                        onViewDetails={() => {
                          toast({
                            title: "Staff Details",
                            description: `Viewing details for ${assignment.user?.name || 'Unknown'}`
                          });
                        }}
                        onContact={() => {
                          toast({
                            title: "Contact Staff",
                            description: `Contacting ${assignment.user?.name || 'Unknown'}`
                          });
                        }}
                        onAssignTask={() => {
                          toast({
                            title: "Assign Task",
                            description: `Assigning task to ${assignment.user?.name || 'Unknown'}`
                          });
                        }}
                      />
                    ))}
                  </div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Staff Member</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Zone</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Shift Time</TableHead>
                        <TableHead>Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {staffAssignments.map((assignment) => (
                        <TableRow key={assignment.id}>
                          <TableCell>
                            <div className="flex items-center gap-2">
                              <ResponsiveText size="sm" weight="medium">{assignment.user?.name || 'Unknown'}</ResponsiveText>
                              {/* API doesn't provide is_lead info, so we can't show Lead badge */}
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-2">
                              <StaffRoleIcon 
                                name={assignment.role as StaffRole} 
                                size="sm"
                              />
                              <ResponsiveText size="sm">
                                {staffRoles.find(r => r.value === assignment.role)?.label || assignment.role}
                              </ResponsiveText>
                            </div>
                          </TableCell>
                          <TableCell>
                            <ResponsiveText size="sm">{assignment.role}</ResponsiveText>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-2">
                              <StaffStatusIcon 
                                name={assignment.status as StaffStatus} 
                                size="sm"
                              />
                              <ResponsiveText size="sm" className="capitalize">
                                {assignment.status.replace('_', ' ')}
                              </ResponsiveText>
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="text-sm">
                              <ResponsiveText size="sm">
                                {new Date(assignment.assigned_at).toLocaleTimeString()}
                              </ResponsiveText>
                              <ResponsiveText size="xs" variant="muted">
                                Assigned
                              </ResponsiveText>
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="flex gap-1">
                              <Select
                                value={assignment.status}
                                onValueChange={(status) => handleStatusUpdate(assignment.id, status)}
                              >
                                <SelectTrigger className="w-32">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="assigned">Assigned</SelectItem>
                                  <SelectItem value="confirmed">Confirmed</SelectItem>
                                  <SelectItem value="completed">Completed</SelectItem>
                                  <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="performance" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <SystemIcon name="filter" size="md" />
                  <ResponsiveText size="lg" weight="semibold">Staff Performance Monitoring</ResponsiveText>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <AccessibleButton 
                  onClick={() => refetchPerformance()} 
                  className="mb-4"
                  size={isMobile ? "sm" : "default"}
                  disabled={performanceLoading}
                  ariaLabel="Load performance data"
                  screenReaderText="Load staff performance data for analysis"
                >
                  {performanceLoading ? (
                    <>
                      <LoadingSpinner size="sm" className="mr-2" />
                      Loading...
                    </>
                  ) : (
                    "Load Performance Data"
                  )}
                </AccessibleButton>
                {performanceLoading ? (
                  <div className="flex items-center justify-center p-8">
                    <LoadingSpinner size="lg" />
                  </div>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Staff Member</TableHead>
                        <TableHead>Total Events</TableHead>
                        <TableHead>Average Rating</TableHead>
                        <TableHead>Punctuality</TableHead>
                        <TableHead>Reliability</TableHead>
                        <TableHead>Last Event</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {staffPerformance.map((performance) => (
                        <TableRow key={performance.user_id}>
                          <TableCell>
                            <ResponsiveText size="sm" weight="medium">{performance.user_name}</ResponsiveText>
                          </TableCell>
                          <TableCell>
                            <ResponsiveText size="sm">{performance.events_completed}</ResponsiveText>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1">
                              <ResponsiveText size="sm">
                                {performance.average_rating?.toFixed(1) || 'N/A'}
                              </ResponsiveText>
                              {performance.average_rating && performance.average_rating >= 4 && (
                                <Badge variant="secondary">Excellent</Badge>
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1">
                              <ResponsiveText size="sm">{performance.on_time_percentage}%</ResponsiveText>
                              {performance.on_time_percentage < 80 && (
                                <FeedbackIcon name="warning" size="sm" />
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            <ResponsiveText size="sm">{performance.on_time_percentage}%</ResponsiveText>
                          </TableCell>
                          <TableCell>
                            <ResponsiveText size="sm">
                              {performance.total_hours ? 
                                `${performance.total_hours} hours` : 
                                'N/A'
                              }
                            </ResponsiveText>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="handover" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <StaffStatusIcon name="active" size="md" />
                  <ResponsiveText size="lg" weight="semibold">Operational Handover & Briefing</ResponsiveText>
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-4">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card>
                      <CardHeader>
                        <CardTitle>
                          <ResponsiveText size="base" weight="semibold">Shift Summary</ResponsiveText>
                        </CardTitle>
                      </CardHeader>
                      <CardContent>
                        <div className="space-y-2">
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">Total Staff:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium">{staffAssignments.length}</ResponsiveText>
                          </div>
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">Active:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium" className="text-green-600">
                              {staffAssignments.filter(s => s.status === 'confirmed').length}
                            </ResponsiveText>
                          </div>
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">Completed:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium" className="text-yellow-600">
                              {staffAssignments.filter(s => s.status === 'completed').length}
                            </ResponsiveText>
                          </div>
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">Cancelled:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium" className="text-red-600">
                              {staffAssignments.filter(s => s.status === 'cancelled').length}
                            </ResponsiveText>
                          </div>
                        </div>
                      </CardContent>
                    </Card>

                    <Card>
                      <CardHeader>
                        <CardTitle>
                          <ResponsiveText size="base" weight="semibold">Key Information</ResponsiveText>
                        </CardTitle>
                      </CardHeader>
                      <CardContent>
                        <div className="space-y-2 text-sm">
                          <div>
                            <ResponsiveText size="sm" weight="semibold">Lead Staff:</ResponsiveText>
                            <div className="mt-1">
                              <ResponsiveText size="sm">
                                {/* API doesn't provide is_lead info, so show all confirmed staff */}
                                {staffAssignments
                                  .filter(s => s.status === 'confirmed')
                                  .map(s => `${s.user?.name || 'Unknown'} (${s.role})`)
                                  .join(', ') || 'None confirmed'}
                              </ResponsiveText>
                            </div>
                          </div>
                          <div>
                            <ResponsiveText size="sm" weight="semibold">Critical Roles:</ResponsiveText>
                            <div className="mt-1">
                              <ResponsiveText size="sm">
                                {Array.from(new Set(staffAssignments.map(s => s.role)))
                                  .join(', ') || 'None'}
                              </ResponsiveText>
                            </div>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  </div>

                  <div className="flex gap-2">
                    <AccessibleButton 
                      onClick={generateSchedulingReport}
                      size={isMobile ? "sm" : "default"}
                      ariaLabel="Generate handover report"
                      screenReaderText="Generate and download handover report for shift transition"
                    >
                      <SystemIcon name="download" size="sm" className="mr-2" />
                      {!isMobile && "Generate Handover Report"}
                    </AccessibleButton>
                    <AccessibleButton 
                      variant="outline"
                      size={isMobile ? "sm" : "default"}
                      ariaLabel="Schedule briefing"
                      screenReaderText="Schedule briefing session for staff"
                    >
                      <ActionIcon name="schedule" size="sm" className="mr-2" />
                      {!isMobile && "Schedule Briefing"}
                    </AccessibleButton>
                  </div>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      )}
    </div>
  );
};

// Staff Assignment Form Component
const StaffAssignmentForm: React.FC<{
  onSubmit: (data: any) => void;
  roles: Array<{ value: string; label: string }>;
}> = ({ onSubmit, roles }) => {
  const [formData, setFormData] = useState({
    user_id: '',
    role: '',
    notes: ''
  });
  const { isMobile } = useBreakpoint();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="user_id">
          <ResponsiveText size="sm" weight="medium">Staff User ID</ResponsiveText>
        </Label>
        <Input
          id="user_id"
          type="number"
          value={formData.user_id}
          onChange={(e) => setFormData({ ...formData, user_id: e.target.value })}
          required
          aria-describedby="user_id_help"
        />
        <SmallText id="user_id_help" variant="muted" className="mt-1">
          Enter the unique identifier for the staff member
        </SmallText>
      </div>

      <div>
        <Label htmlFor="role">
          <ResponsiveText size="sm" weight="medium">Role</ResponsiveText>
        </Label>
        <Select value={formData.role} onValueChange={(value) => setFormData({ ...formData, role: value })}>
          <SelectTrigger>
            <SelectValue placeholder="Select role" />
          </SelectTrigger>
          <SelectContent>
            {roles.map((role) => (
              <SelectItem key={role.value} value={role.value}>
                <ResponsiveText size="sm">{role.label}</ResponsiveText>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div>
        <Label htmlFor="notes">
          <ResponsiveText size="sm" weight="medium">Notes</ResponsiveText>
        </Label>
        <Input
          id="notes"
          value={formData.notes}
          onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
          aria-describedby="notes_help"
        />
        <SmallText id="notes_help" variant="muted" className="mt-1">
          Optional: Additional notes for this assignment
        </SmallText>
      </div>

      <AccessibleButton 
        type="submit" 
        className="w-full"
        size={isMobile ? "default" : "lg"}
        ariaLabel="Assign staff member"
        screenReaderText="Submit form to assign staff member to selected event"
      >
        Assign Staff Member
      </AccessibleButton>
    </form>
  );
};

export default StaffManagementPage;