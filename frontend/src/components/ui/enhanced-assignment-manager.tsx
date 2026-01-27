import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import { EnhancedCard } from "./enhanced-card"
import { EnhancedBadge } from "./enhanced-badge"
import { Progress } from "./progress"
import { Button } from "./button"
import { 
  Calendar,
  Clock,
  Users,
  MapPin,
  AlertTriangle,
  CheckCircle,
  Plus,
  Minus,
  MoreVertical,
  GripVertical
} from "lucide-react"

// Assignment Management Types
export interface StaffAssignment {
  id: string;
  staffId: string;
  staffName: string;
  role: string;
  zone: string;
  shiftStart: string;
  shiftEnd: string;
  status: 'assigned' | 'checked_in' | 'active' | 'break' | 'checked_out' | 'absent';
}

export interface ZoneCapacity {
  zoneId: string;
  zoneName: string;
  requiredStaff: number;
  assignedStaff: number;
  activeStaff: number;
  maxCapacity: number;
}

export interface TimeSlot {
  id: string;
  startTime: string;
  endTime: string;
  assignments: StaffAssignment[];
}

// Enhanced Assignment Manager Variants
const enhancedAssignmentManagerVariants = cva(
  "space-y-6",
  {
    variants: {
      view: {
        grid: "grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6",
        timeline: "flex flex-col space-y-4",
        capacity: "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
      }
    },
    defaultVariants: {
      view: "grid"
    }
  }
)

// Drag and Drop Context
interface DragDropContextType {
  draggedItem: StaffAssignment | null;
  setDraggedItem: (item: StaffAssignment | null) => void;
  onDrop: (item: StaffAssignment, targetZone: string, targetTime?: string) => void;
}

const DragDropContext = React.createContext<DragDropContextType | null>(null)

// Draggable Staff Card Component
interface DraggableStaffCardProps {
  assignment: StaffAssignment;
  onEdit?: () => void;
  onRemove?: () => void;
}

const DraggableStaffCard: React.FC<DraggableStaffCardProps> = ({
  assignment,
  onEdit,
  onRemove
}) => {
  const [isDragging, setIsDragging] = React.useState(false)
  const dragDropContext = React.useContext(DragDropContext)

  const handleDragStart = (e: React.DragEvent) => {
    setIsDragging(true)
    dragDropContext?.setDraggedItem(assignment)
    e.dataTransfer.effectAllowed = 'move'
  }

  const handleDragEnd = () => {
    setIsDragging(false)
    dragDropContext?.setDraggedItem(null)
  }

  const statusColors = {
    assigned: 'info',
    checked_in: 'success',
    active: 'success',
    break: 'warning',
    checked_out: 'secondary',
    absent: 'error'
  } as const

  return (
    <div
      draggable
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
      className={cn(
        "group cursor-move transition-all duration-200",
        isDragging && "opacity-50 scale-95"
      )}
    >
      <EnhancedCard
        variant="outlined"
        size="sm"
        interactive
        className={cn(
          "hover:shadow-md transition-all duration-200",
          isDragging && "shadow-lg border-blue-300"
        )}
      >
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-2">
            <GripVertical className="w-4 h-4 text-gray-400 group-hover:text-gray-600" />
            <div>
              <h4 className="font-medium text-sm">{assignment.staffName}</h4>
              <p className="text-xs text-gray-500">{assignment.role}</p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            <EnhancedBadge
              variant="status"
              color={statusColors[assignment.status]}
              size="xs"
            >
              {assignment.status}
            </EnhancedBadge>
            <Button
              variant="ghost"
              size="sm"
              className="opacity-0 group-hover:opacity-100 transition-opacity p-1 h-6 w-6"
            >
              <MoreVertical className="w-3 h-3" />
            </Button>
          </div>
        </div>

        <div className="space-y-1 text-xs text-gray-600">
          <div className="flex items-center gap-1">
            <MapPin className="w-3 h-3" />
            <span>{assignment.zone}</span>
          </div>
          <div className="flex items-center gap-1">
            <Clock className="w-3 h-3" />
            <span>
              {new Date(assignment.shiftStart).toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit' 
              })} - {new Date(assignment.shiftEnd).toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit' 
              })}
            </span>
          </div>
        </div>
      </EnhancedCard>
    </div>
  )
}

// Drop Zone Component
interface DropZoneProps {
  zoneId: string;
  zoneName: string;
  children: React.ReactNode;
  onDrop?: (assignment: StaffAssignment) => void;
  className?: string;
}

const DropZone: React.FC<DropZoneProps> = ({
  zoneId,
  zoneName,
  children,
  onDrop,
  className
}) => {
  const [isOver, setIsOver] = React.useState(false)
  const dragDropContext = React.useContext(DragDropContext)

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault()
    setIsOver(true)
  }

  const handleDragLeave = () => {
    setIsOver(false)
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setIsOver(false)
    
    if (dragDropContext?.draggedItem) {
      dragDropContext.onDrop(dragDropContext.draggedItem, zoneId)
      onDrop?.(dragDropContext.draggedItem)
    }
  }

  return (
    <div
      onDragOver={handleDragOver}
      onDragLeave={handleDragLeave}
      onDrop={handleDrop}
      className={cn(
        "transition-all duration-200 rounded-lg border-2 border-dashed",
        isOver 
          ? "border-blue-400 bg-blue-50" 
          : "border-gray-200 hover:border-gray-300",
        className
      )}
    >
      {children}
    </div>
  )
}

// Zone Capacity Indicator Component
interface ZoneCapacityIndicatorProps {
  capacity: ZoneCapacity;
  assignments: StaffAssignment[];
}

const ZoneCapacityIndicator: React.FC<ZoneCapacityIndicatorProps> = ({
  capacity,
  assignments
}) => {
  const utilizationPercentage = (capacity.assignedStaff / capacity.requiredStaff) * 100
  const activePercentage = (capacity.activeStaff / capacity.requiredStaff) * 100
  
  const getStatusColor = () => {
    if (capacity.assignedStaff < capacity.requiredStaff * 0.8) {
      return 'error' // Under-staffed
    } else if (capacity.assignedStaff > capacity.maxCapacity) {
      return 'warning' // Over-capacity
    } else {
      return 'success' // Optimal
    }
  }

  const getStatusIcon = () => {
    const status = getStatusColor()
    switch (status) {
      case 'error':
        return <AlertTriangle className="w-4 h-4" />
      case 'warning':
        return <AlertTriangle className="w-4 h-4" />
      case 'success':
        return <CheckCircle className="w-4 h-4" />
      default:
        return <Users className="w-4 h-4" />
    }
  }

  return (
    <DropZone zoneId={capacity.zoneId} zoneName={capacity.zoneName}>
      <EnhancedCard variant="elevated" size="sm" className="h-full">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <MapPin className="w-4 h-4 text-gray-500" />
            <h3 className="font-semibold text-sm">{capacity.zoneName}</h3>
          </div>
          <EnhancedBadge
            variant="status"
            color={getStatusColor()}
            size="xs"
            icon={() => getStatusIcon()}
          >
            {capacity.assignedStaff}/{capacity.requiredStaff}
          </EnhancedBadge>
        </div>

        {/* Capacity Progress Bars */}
        <div className="space-y-3 mb-4">
          <div>
            <div className="flex justify-between items-center mb-1">
              <span className="text-xs text-gray-600">Assigned</span>
              <span className="text-xs font-medium">
                {Math.round(utilizationPercentage)}%
              </span>
            </div>
            <Progress 
              value={utilizationPercentage} 
              className="h-2"
            />
          </div>

          <div>
            <div className="flex justify-between items-center mb-1">
              <span className="text-xs text-gray-600">Active</span>
              <span className="text-xs font-medium">
                {Math.round(activePercentage)}%
              </span>
            </div>
            <Progress 
              value={activePercentage} 
              className="h-2"
              style={{
                background: `linear-gradient(90deg, ${designTokens.colors.semantic.success[500]}, ${designTokens.colors.semantic.success[600]})`
              }}
            />
          </div>
        </div>

        {/* Staff Assignments */}
        <div className="space-y-2 min-h-[100px]">
          {assignments
            .filter(a => a.zone === capacity.zoneName)
            .map(assignment => (
              <DraggableStaffCard
                key={assignment.id}
                assignment={assignment}
              />
            ))}
        </div>

        {/* Add Staff Button */}
        <Button
          variant="outline"
          size="sm"
          className="w-full mt-3 text-xs"
        >
          <Plus className="w-3 h-3 mr-1" />
          Assign Staff
        </Button>
      </EnhancedCard>
    </DropZone>
  )
}

// Timeline View Component
interface TimelineViewProps {
  timeSlots: TimeSlot[];
  assignments: StaffAssignment[];
}

const TimelineView: React.FC<TimelineViewProps> = ({
  timeSlots,
  assignments
}) => {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 mb-4">
        <Calendar className="w-5 h-5 text-gray-500" />
        <h3 className="font-semibold">Shift Timeline</h3>
      </div>

      {timeSlots.map(slot => (
        <DropZone
          key={slot.id}
          zoneId={`timeline-${slot.id}`}
          zoneName={`${slot.startTime} - ${slot.endTime}`}
        >
          <EnhancedCard variant="outlined" size="sm">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <Clock className="w-4 h-4 text-gray-500" />
                <span className="font-medium text-sm">
                  {new Date(slot.startTime).toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                  })} - {new Date(slot.endTime).toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                  })}
                </span>
              </div>
              <EnhancedBadge variant="status" color="info" size="xs">
                {slot.assignments.length} staff
              </EnhancedBadge>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
              {slot.assignments.map(assignment => (
                <DraggableStaffCard
                  key={assignment.id}
                  assignment={assignment}
                />
              ))}
            </div>

            {slot.assignments.length === 0 && (
              <div className="text-center py-8 text-gray-500">
                <Users className="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p className="text-sm">No staff assigned to this time slot</p>
                <Button variant="outline" size="sm" className="mt-2">
                  <Plus className="w-3 h-3 mr-1" />
                  Add Staff
                </Button>
              </div>
            )}
          </EnhancedCard>
        </DropZone>
      ))}
    </div>
  )
}

// Main Enhanced Assignment Manager Component
export interface EnhancedAssignmentManagerProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedAssignmentManagerVariants> {
  assignments: StaffAssignment[];
  zoneCapacities: ZoneCapacity[];
  timeSlots?: TimeSlot[];
  onAssignmentMove?: (assignment: StaffAssignment, targetZone: string, targetTime?: string) => void;
  onAssignmentEdit?: (assignment: StaffAssignment) => void;
  onAssignmentRemove?: (assignmentId: string) => void;
}

const EnhancedAssignmentManager = React.forwardRef<HTMLDivElement, EnhancedAssignmentManagerProps>(
  ({ 
    className,
    view = "capacity",
    assignments,
    zoneCapacities,
    timeSlots = [],
    onAssignmentMove,
    onAssignmentEdit,
    onAssignmentRemove,
    ...props 
  }, ref) => {
    const [draggedItem, setDraggedItem] = React.useState<StaffAssignment | null>(null)

    const handleDrop = (assignment: StaffAssignment, targetZone: string, targetTime?: string) => {
      onAssignmentMove?.(assignment, targetZone, targetTime)
    }

    const dragDropContextValue: DragDropContextType = {
      draggedItem,
      setDraggedItem,
      onDrop: handleDrop
    }

    return (
      <DragDropContext.Provider value={dragDropContextValue}>
        <div
          ref={ref}
          className={cn(enhancedAssignmentManagerVariants({ view }), className)}
          {...props}
        >
          {view === 'capacity' && (
            <>
              {zoneCapacities.map(capacity => (
                <ZoneCapacityIndicator
                  key={capacity.zoneId}
                  capacity={capacity}
                  assignments={assignments}
                />
              ))}
            </>
          )}

          {view === 'timeline' && (
            <TimelineView
              timeSlots={timeSlots}
              assignments={assignments}
            />
          )}

          {view === 'grid' && (
            <>
              {assignments.map(assignment => (
                <DraggableStaffCard
                  key={assignment.id}
                  assignment={assignment}
                  onEdit={() => onAssignmentEdit?.(assignment)}
                  onRemove={() => onAssignmentRemove?.(assignment.id)}
                />
              ))}
            </>
          )}
        </div>
      </DragDropContext.Provider>
    )
  }
)

EnhancedAssignmentManager.displayName = "EnhancedAssignmentManager"

export {
  EnhancedAssignmentManager,
  DraggableStaffCard,
  DropZone,
  ZoneCapacityIndicator,
  TimelineView,
  enhancedAssignmentManagerVariants
}