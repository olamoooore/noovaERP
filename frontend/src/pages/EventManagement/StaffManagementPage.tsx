import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AccessibleButton } from '@/components/ui/accessible-button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ResponsiveText, Heading1, Heading2, Heading3, BodyText, SmallText } from '@/components/ui/responsive-text';
import { useBreakpoint } from '@/hooks/useBreakpoint';
import { EnhancedStaffCard, type StaffStatus, type StaffRole } from '@/components/ui/enhanced-staff-card';
import { 
  EnhancedAssignmentManager, 
  type StaffAssignment as EnhancedStaffAssignment,
  type ZoneCapacity 
} from '@/components/ui/enhanced-assignment-manager';
import { Users, UserPlus, Calendar, BarChart3, FileText, Clock, AlertCircle, Grid3X3, List, Layout } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { 
  StaffRoleIcon, 
  StaffStatusIcon, 
  ActionIcon, 
  NavigationIcon, 
  SystemIcon, 
  FeedbackIcon 
} from '@/components/ui/enhanced-icon';

interface StaffAssignment {
  id: number;
  staff_user_id: string;
  staff_name: string;
  role: string;
  zone: string;
  status: string;
  shift_start: string;
  shift_end: string;
  is_lead: boolean;
  performance_rating?: number;
  checked_in_at?: string;
  checked_out_at?: string;
}

interface Event {
  id: number;
  title: string;
  start_date: string;
  end_date: string;
  status: string;
}

interface StaffPerformance {
  staff_user_id: string;
  staff_name: string;
  total_events: number;
  average_rating: number;
  punctuality_score: number;
  reliability_score: number;
  last_event_date: string;
}

const StaffManagementPage: React.FC = () => {
  const [events, setEvents] = useState<Event[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<number | null>(null);
  const [staffAssignments, setStaffAssignments] = useState<StaffAssignment[]>([]);
  const [staffPerformance, setStaffPerformance] = useState<StaffPerformance[]>([]);
  const [loading, setLoading] = useState(false);
  const [showAssignDialog, setShowAssignDialog] = useState(false);
  const [viewMode, setViewMode] = useState<'cards' | 'table' | 'assignment'>('cards');
  const { toast } = useToast();
  const { current: breakpoint, isMobile, isTablet } = useBreakpoint();

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

  const statusColors = {
    assigned: 'bg-blue-100 text-blue-800',
    checked_in: 'bg-green-100 text-green-800',
    active: 'bg-green-100 text-green-800',
    break: 'bg-yellow-100 text-yellow-800',
    checked_out: 'bg-gray-100 text-gray-800',
    absent: 'bg-red-100 text-red-800'
  };

  useEffect(() => {
    fetchEvents();
  }, []);

  useEffect(() => {
    if (selectedEvent) {
      fetchStaffAssignments(selectedEvent);
    }
  }, [selectedEvent]);

  const fetchEvents = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/v1/event-management/events', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.ok) {
        const data = await response.json();
        setEvents(data.data || []);
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to fetch events",
        variant: "destructive"
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchStaffAssignments = async (eventId: number) => {
    try {
      setLoading(true);
      const response = await fetch(`/api/v1/event-management/staff/events/${eventId}/assignments`, {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.ok) {
        const data = await response.json();
        setStaffAssignments(data.data || []);
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to fetch staff assignments",
        variant: "destructive"
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchStaffPerformance = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/v1/event-management/staff/performance-report', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.ok) {
        const data = await response.json();
        setStaffPerformance(data.data || []);
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to fetch staff performance data",
        variant: "destructive"
      });
    } finally {
      setLoading(false);
    }
  };

  const handleAssignStaff = async (assignmentData: any) => {
    try {
      const response = await fetch(`/api/v1/event-management/staff/events/${selectedEvent}/assign`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(assignmentData)
      });
      
      if (response.ok) {
        toast({
          title: "Success",
          description: "Staff member assigned successfully"
        });
        setShowAssignDialog(false);
        if (selectedEvent) {
          fetchStaffAssignments(selectedEvent);
        }
      } else {
        throw new Error('Failed to assign staff');
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to assign staff member",
        variant: "destructive"
      });
    }
  };

  const handleStatusUpdate = async (assignmentId: number, status: string) => {
    try {
      const response = await fetch(`/api/v1/event-management/staff/assignments/${assignmentId}/status`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ status })
      });
      
      if (response.ok) {
        toast({
          title: "Success",
          description: "Staff status updated successfully"
        });
        if (selectedEvent) {
          fetchStaffAssignments(selectedEvent);
        }
      } else {
        throw new Error('Failed to update status');
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to update staff status",
        variant: "destructive"
      });
    }
  };

  const generateSchedulingReport = async () => {
    if (!selectedEvent) return;
    
    try {
      const response = await fetch(`/api/v1/event-management/staff/events/${selectedEvent}/scheduling-report`, {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.ok) {
        const data = await response.json();
        // Handle report download or display
        toast({
          title: "Success",
          description: "Scheduling report generated successfully"
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to generate scheduling report",
        variant: "destructive"
      });
    }
  };

  const generateZoneCapacities = (assignments: StaffAssignment[]): ZoneCapacity[] => {
    const zones = Array.from(new Set(assignments.map(a => a.zone).filter(Boolean)));
    
    return zones.map(zone => {
      const zoneAssignments = assignments.filter(a => a.zone === zone);
      const activeStaff = zoneAssignments.filter(a => a.status === 'active').length;
      
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

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <Heading1 className="text-2xl md:text-3xl">Staff Management</Heading1>
          <BodyText variant="muted">Manage event staff assignments, scheduling, and performance</BodyText>
        </div>
        <div className="flex gap-2">
          <AccessibleButton 
            onClick={generateSchedulingReport} 
            variant="outline" 
            disabled={!selectedEvent}
            size={isMobile ? "sm" : "default"}
            icon={SystemIcon}
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
                icon={ActionIcon}
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
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <SystemIcon name="search" size="md" />
            <ResponsiveText size="lg" weight="semibold">Select Event</ResponsiveText>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <Select value={selectedEvent?.toString()} onValueChange={(value) => setSelectedEvent(parseInt(value))}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Select an event to manage staff" />
            </SelectTrigger>
            <SelectContent>
              {events.map((event) => (
                <SelectItem key={event.id} value={event.id.toString()}>
                  <ResponsiveText size="sm">
                    {event.title} - {new Date(event.start_date).toLocaleDateString()}
                  </ResponsiveText>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardContent>
      </Card>

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
                {viewMode === 'assignment' ? (
                  <EnhancedAssignmentManager
                    view="capacity"
                    assignments={staffAssignments.map(assignment => ({
                      id: assignment.id.toString(),
                      staffId: assignment.staff_user_id,
                      staffName: assignment.staff_name,
                      role: assignment.role,
                      zone: assignment.zone,
                      shiftStart: assignment.shift_start,
                      shiftEnd: assignment.shift_end,
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
                    onAssignmentRemove={(assignmentId) => {
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
                        staffId={assignment.staff_user_id}
                        staffName={assignment.staff_name}
                        role={assignment.role as StaffRole}
                        zone={assignment.zone}
                        status={assignment.status as StaffStatus}
                        isLead={assignment.is_lead}
                        shiftStart={assignment.shift_start}
                        shiftEnd={assignment.shift_end}
                        checkedInAt={assignment.checked_in_at}
                        checkedOutAt={assignment.checked_out_at}
                        performanceRating={assignment.performance_rating}
                        onStatusChange={(status) => handleStatusUpdate(assignment.id, status)}
                        onViewDetails={() => {
                          toast({
                            title: "Staff Details",
                            description: `Viewing details for ${assignment.staff_name}`
                          });
                        }}
                        onContact={() => {
                          toast({
                            title: "Contact Staff",
                            description: `Contacting ${assignment.staff_name}`
                          });
                        }}
                        onAssignTask={() => {
                          toast({
                            title: "Assign Task",
                            description: `Assigning task to ${assignment.staff_name}`
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
                              <ResponsiveText size="sm" weight="medium">{assignment.staff_name}</ResponsiveText>
                              {assignment.is_lead && (
                                <Badge variant="secondary">Lead</Badge>
                              )}
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
                            <ResponsiveText size="sm">{assignment.zone}</ResponsiveText>
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
                                {new Date(assignment.shift_start).toLocaleTimeString()}
                              </ResponsiveText>
                              <ResponsiveText size="xs" variant="muted">
                                to {new Date(assignment.shift_end).toLocaleTimeString()}
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
                                  <SelectItem value="checked_in">Checked In</SelectItem>
                                  <SelectItem value="active">Active</SelectItem>
                                  <SelectItem value="break">On Break</SelectItem>
                                  <SelectItem value="checked_out">Checked Out</SelectItem>
                                  <SelectItem value="absent">Absent</SelectItem>
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
                  onClick={fetchStaffPerformance} 
                  className="mb-4"
                  size={isMobile ? "sm" : "default"}
                  ariaLabel="Load performance data"
                  screenReaderText="Load staff performance data for analysis"
                >
                  Load Performance Data
                </AccessibleButton>
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
                      <TableRow key={performance.staff_user_id}>
                        <TableCell>
                          <ResponsiveText size="sm" weight="medium">{performance.staff_name}</ResponsiveText>
                        </TableCell>
                        <TableCell>
                          <ResponsiveText size="sm">{performance.total_events}</ResponsiveText>
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
                            <ResponsiveText size="sm">{performance.punctuality_score}%</ResponsiveText>
                            {performance.punctuality_score < 80 && (
                              <FeedbackIcon name="warning" size="sm" />
                            )}
                          </div>
                        </TableCell>
                        <TableCell>
                          <ResponsiveText size="sm">{performance.reliability_score}%</ResponsiveText>
                        </TableCell>
                        <TableCell>
                          <ResponsiveText size="sm">
                            {performance.last_event_date ? 
                              new Date(performance.last_event_date).toLocaleDateString() : 
                              'N/A'
                            }
                          </ResponsiveText>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
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
                              {staffAssignments.filter(s => s.status === 'active').length}
                            </ResponsiveText>
                          </div>
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">On Break:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium" className="text-yellow-600">
                              {staffAssignments.filter(s => s.status === 'break').length}
                            </ResponsiveText>
                          </div>
                          <div className="flex justify-between">
                            <ResponsiveText size="sm">Absent:</ResponsiveText>
                            <ResponsiveText size="sm" weight="medium" className="text-red-600">
                              {staffAssignments.filter(s => s.status === 'absent').length}
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
                                {staffAssignments
                                  .filter(s => s.is_lead)
                                  .map(s => `${s.staff_name} (${s.role})`)
                                  .join(', ') || 'None assigned'}
                              </ResponsiveText>
                            </div>
                          </div>
                          <div>
                            <ResponsiveText size="sm" weight="semibold">Critical Zones:</ResponsiveText>
                            <div className="mt-1">
                              <ResponsiveText size="sm">
                                {Array.from(new Set(staffAssignments.map(s => s.zone)))
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
                      icon={SystemIcon}
                      ariaLabel="Generate handover report"
                      screenReaderText="Generate and download handover report for shift transition"
                    >
                      <SystemIcon name="download" size="sm" className="mr-2" />
                      {!isMobile && "Generate Handover Report"}
                    </AccessibleButton>
                    <AccessibleButton 
                      variant="outline"
                      size={isMobile ? "sm" : "default"}
                      icon={ActionIcon}
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
    staff_user_id: '',
    role: '',
    zone: '',
    shift_start: '',
    shift_end: '',
    is_lead: false,
    responsibilities: '',
    contact_number: '',
    emergency_contact: ''
  });
  const { isMobile } = useBreakpoint();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="staff_user_id">
          <ResponsiveText size="sm" weight="medium">Staff User ID</ResponsiveText>
        </Label>
        <Input
          id="staff_user_id"
          value={formData.staff_user_id}
          onChange={(e) => setFormData({ ...formData, staff_user_id: e.target.value })}
          required
          aria-describedby="staff_user_id_help"
        />
        <SmallText id="staff_user_id_help" variant="muted" className="mt-1">
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
        <Label htmlFor="zone">
          <ResponsiveText size="sm" weight="medium">Zone</ResponsiveText>
        </Label>
        <Input
          id="zone"
          value={formData.zone}
          onChange={(e) => setFormData({ ...formData, zone: e.target.value })}
          aria-describedby="zone_help"
        />
        <SmallText id="zone_help" variant="muted" className="mt-1">
          Specify the zone or area for assignment
        </SmallText>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <Label htmlFor="shift_start">
            <ResponsiveText size="sm" weight="medium">Shift Start</ResponsiveText>
          </Label>
          <Input
            id="shift_start"
            type="datetime-local"
            value={formData.shift_start}
            onChange={(e) => setFormData({ ...formData, shift_start: e.target.value })}
          />
        </div>
        <div>
          <Label htmlFor="shift_end">
            <ResponsiveText size="sm" weight="medium">Shift End</ResponsiveText>
          </Label>
          <Input
            id="shift_end"
            type="datetime-local"
            value={formData.shift_end}
            onChange={(e) => setFormData({ ...formData, shift_end: e.target.value })}
          />
        </div>
      </div>

      <div className="flex items-center space-x-2">
        <input
          type="checkbox"
          id="is_lead"
          checked={formData.is_lead}
          onChange={(e) => setFormData({ ...formData, is_lead: e.target.checked })}
          className="min-w-[16px] min-h-[16px]"
        />
        <Label htmlFor="is_lead">
          <ResponsiveText size="sm" weight="medium">Lead Staff Member</ResponsiveText>
        </Label>
      </div>

      <div>
        <Label htmlFor="responsibilities">
          <ResponsiveText size="sm" weight="medium">Responsibilities</ResponsiveText>
        </Label>
        <Input
          id="responsibilities"
          value={formData.responsibilities}
          onChange={(e) => setFormData({ ...formData, responsibilities: e.target.value })}
          aria-describedby="responsibilities_help"
        />
        <SmallText id="responsibilities_help" variant="muted" className="mt-1">
          Optional: Specific duties and responsibilities
        </SmallText>
      </div>

      <div>
        <Label htmlFor="contact_number">
          <ResponsiveText size="sm" weight="medium">Contact Number</ResponsiveText>
        </Label>
        <Input
          id="contact_number"
          type="tel"
          value={formData.contact_number}
          onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })}
          aria-describedby="contact_help"
        />
        <SmallText id="contact_help" variant="muted" className="mt-1">
          Primary contact number for the staff member
        </SmallText>
      </div>

      <div>
        <Label htmlFor="emergency_contact">
          <ResponsiveText size="sm" weight="medium">Emergency Contact</ResponsiveText>
        </Label>
        <Input
          id="emergency_contact"
          type="tel"
          value={formData.emergency_contact}
          onChange={(e) => setFormData({ ...formData, emergency_contact: e.target.value })}
          aria-describedby="emergency_help"
        />
        <SmallText id="emergency_help" variant="muted" className="mt-1">
          Emergency contact number
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