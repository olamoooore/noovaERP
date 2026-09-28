import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { 
  Star, 
  Upload, 
  X, 
  ThumbsUp, 
  MessageSquare, 
  Camera,
  Send,
  Heart
} from 'lucide-react';
import { format } from 'date-fns';
import { eventManagementAPI, FeedbackData } from '@/lib/event-management-api';
import { useCustomerDashboard } from '@/hooks/queries';
import { 
  EventManagementErrorHandler, 
  ErrorSeverity
} from '@/lib/error-handling';
import { 
  useEventLoadingStates, 
  EVENT_LOADING_KEYS 
} from '@/lib/loading-states';
import { 
  EventLoading
} from '@/components/ui/event-loading';

interface FeedbackType {
  name: string;
  description: string;
  requires_comment: boolean;
}

interface RatingCategory {
  name: string;
  description: string;
  required: boolean;
}

interface FeedbackStats {
  total_reviews: number;
  average_rating?: number;
  rating_distribution: Record<number, { count: number; percentage: number }>;
  category_averages: {
    venue?: number;
    organization?: number;
    value?: number;
  };
}

/**
 * FeedbackSystem Component
 * 
 * Comprehensive feedback and review system for post-event experiences.
 * Includes submission forms, review display, and feedback management.
 * 
 * Requirements: 16.4
 */
const FeedbackSystem: React.FC = () => {
  const [activeTab, setActiveTab] = useState('submit');
  const [feedbackTypes, setFeedbackTypes] = useState<Record<string, FeedbackType>>({});
  const [ratingCategories, setRatingCategories] = useState<Record<string, RatingCategory>>({});
  const [myFeedback, setMyFeedback] = useState<FeedbackData[]>([]);
  const [eventFeedback, setEventFeedback] = useState<FeedbackData[]>([]);
  const [feedbackStats, setFeedbackStats] = useState<FeedbackStats | null>(null);
  const [selectedEventId, setSelectedEventId] = useState<number | null>(null);
  const { data: dashboardData } = useCustomerDashboard();
  
  // Use standardized loading states
  const {
    isEventLoading,
    setEventLoading
  } = useEventLoadingStates();

  // Form state
  const [formData, setFormData] = useState({
    event_id: '',
    booking_id: '',
    feedback_type: 'review',
    overall_rating: 0,
    venue_rating: 0,
    organization_rating: 0,
    value_rating: 0,
    title: '',
    comment: '',
    is_anonymous: false,
    allow_public_display: true,
  });
  const [mediaFiles, setMediaFiles] = useState<File[]>([]);

  const availableEvents = (dashboardData?.upcoming_events ?? []).map((event: any) => ({
    id: Number(event._eventId ?? event.event_id ?? event.id),
    bookingId: Number(event.id),
    title: event.name,
    startDate: event.start_date,
    venue: event.venue,
  })).filter((event, index, list) => event.id && list.findIndex((candidate) => candidate.id === event.id) === index);

  useEffect(() => {
    fetchFeedbackTypes();
    fetchRatingCategories();
    fetchMyFeedback();
  }, []);

  useEffect(() => {
    if (selectedEventId) {
      fetchEventFeedback(selectedEventId);
      fetchFeedbackStats(selectedEventId);
    }
  }, [selectedEventId]);

  const fetchFeedbackTypes = async () => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const response = await eventManagementAPI.getFeedbackTypes();
        const types = response.data?.data ?? response.data;
        if (types && typeof types === 'object' && !Array.isArray(types)) {
          setFeedbackTypes(types);
        }
        return types;
      },
      {
        errorMessage: 'Failed to load feedback types.',
        errorOptions: {
          severity: ErrorSeverity.LOW,
          showToast: false
        }
      }
    );
  };

  const fetchRatingCategories = async () => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const response = await eventManagementAPI.getRatingCategories();
        const cats = response.data?.data ?? response.data;
        if (cats && typeof cats === 'object' && !Array.isArray(cats)) {
          setRatingCategories(cats);
        }
        return cats;
      },
      {
        errorMessage: 'Failed to load rating categories.',
        errorOptions: {
          severity: ErrorSeverity.LOW,
          showToast: false
        }
      }
    );
  };

  const fetchMyFeedback = async () => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const response = await eventManagementAPI.getMyFeedback();
        const payload = response.data?.data ?? response.data;
        setMyFeedback(Array.isArray(payload) ? payload : []);
        return payload;
      },
      {
        loadingState: (loading) => setEventLoading(EVENT_LOADING_KEYS.FEEDBACK_LOAD, loading),
        errorMessage: 'Failed to load your feedback history. Please try again.',
        errorOptions: {
          severity: ErrorSeverity.MEDIUM,
          retryable: true
        }
      }
    );
  };

  const fetchEventFeedback = async (eventId: number) => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const response = await eventManagementAPI.getEventFeedback(eventId);
        const payload = response.data?.data ?? response.data;
        setEventFeedback(Array.isArray(payload) ? payload : []);
        return payload;
      },
      {
        errorMessage: 'Failed to load event reviews. Please try again.',
        errorOptions: {
          severity: ErrorSeverity.MEDIUM,
          retryable: true
        }
      }
    );
  };

  const fetchFeedbackStats = async (eventId: number) => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const response = await eventManagementAPI.getEventFeedbackStats(eventId);
        const stats = response.data?.data ?? response.data;
        if (stats) {
          setFeedbackStats(stats);
        }
        return stats;
      },
      {
        errorMessage: 'Failed to load feedback statistics.',
        errorOptions: {
          severity: ErrorSeverity.LOW,
          showToast: false
        }
      }
    );
  };

  const handleSubmitFeedback = async (e: React.FormEvent) => {
    e.preventDefault();
    
    const success = await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        const formDataToSend = new FormData();
        
        // Add form fields
        Object.entries(formData).forEach(([key, value]) => {
          if (value !== '' && value !== 0) {
            formDataToSend.append(key, value.toString());
          }
        });

        // Add media files
        mediaFiles.forEach((file, index) => {
          formDataToSend.append(`media_files[${index}]`, file);
        });

        const response = await eventManagementAPI.submitFeedbackWithFiles(formDataToSend);
        
        // APIResponse<FeedbackData> has structure { data: FeedbackData, status: number }
        // No need to check .success property
        return response.data;
      },
      {
        loadingState: (loading) => setEventLoading(EVENT_LOADING_KEYS.FEEDBACK_SUBMIT, loading),
        successMessage: 'Feedback submitted successfully!',
        errorMessage: 'Failed to submit feedback. Please try again.',
        errorOptions: {
          severity: ErrorSeverity.HIGH,
          retryable: true
        }
      }
    );

    if (success) {
      // Reset form
      setFormData({
        event_id: '',
        booking_id: '',
        feedback_type: 'review',
        overall_rating: 0,
        venue_rating: 0,
        organization_rating: 0,
        value_rating: 0,
        title: '',
        comment: '',
        is_anonymous: false,
        allow_public_display: true,
      });
      setMediaFiles([]);
      fetchMyFeedback();
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []) as File[];
    if (mediaFiles.length + files.length > 5) {
      alert('Maximum 5 files allowed');
      return;
    }
    setMediaFiles([...mediaFiles, ...files]);
  };

  const removeFile = (index: number) => {
    setMediaFiles(mediaFiles.filter((_, i) => i !== index));
  };

  const handleVoteFeedback = async (feedbackId: number, isHelpful: boolean) => {
    await EventManagementErrorHandler.handleAsyncOperation(
      async () => {
        await eventManagementAPI.voteFeedback(feedbackId, isHelpful);
        return true;
      },
      {
        successMessage: 'Thank you for your vote!',
        errorMessage: 'Failed to record your vote. Please try again.',
        errorOptions: {
          severity: ErrorSeverity.LOW,
          retryable: true
        }
      }
    );

    // Refresh feedback data
    if (selectedEventId) {
      fetchEventFeedback(selectedEventId);
    }
  };

  const renderStarRating = (rating: number, onRatingChange?: (rating: number) => void, size: 'sm' | 'md' | 'lg' = 'md') => {
    const sizeClasses = {
      sm: 'h-4 w-4',
      md: 'h-5 w-5',
      lg: 'h-6 w-6'
    };

    return (
      <div className="flex space-x-1">
        {[1, 2, 3, 4, 5].map((star) => (
          <Star
            key={star}
            className={`${sizeClasses[size]} ${
              star <= rating 
                ? 'text-yellow-400 fill-current' 
                : 'text-gray-300'
            } ${onRatingChange ? 'cursor-pointer hover:text-yellow-400' : ''}`}
            onClick={() => onRatingChange?.(star)}
          />
        ))}
      </div>
    );
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">Event Feedback</h1>
        <p className="text-gray-600">Share your experience and read reviews from other attendees</p>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="w-fit max-w-full">
          <TabsTrigger value="submit">Submit Feedback</TabsTrigger>
          <TabsTrigger value="my-feedback">My Feedback</TabsTrigger>
          <TabsTrigger value="browse">Browse Reviews</TabsTrigger>
        </TabsList>

        {/* Submit Feedback Tab */}
        <TabsContent value="submit" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Submit Event Feedback</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmitFeedback} className="space-y-6">
                {/* Event Selection */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Label htmlFor="event_id">Event *</Label>
                    <Select
                      value={formData.event_id}
                      onValueChange={(value) => {
                        const matchedEvent = availableEvents.find((event) => String(event.id) === value)
                        setFormData({
                          ...formData,
                          event_id: value,
                          booking_id: matchedEvent ? String(matchedEvent.bookingId) : formData.booking_id,
                        })
                      }}
                    >
                      <SelectTrigger id="event_id">
                        <SelectValue placeholder="Select an event" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableEvents.map((event) => (
                          <SelectItem key={event.id} value={String(event.id)}>
                            {event.title}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label htmlFor="booking_id">Booking ID (Optional)</Label>
                    <Select
                      value={formData.booking_id || 'none'}
                      onValueChange={(value) => setFormData({...formData, booking_id: value === 'none' ? '' : value})}
                    >
                      <SelectTrigger id="booking_id">
                        <SelectValue placeholder="Select a booking" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No booking reference</SelectItem>
                        {availableEvents.map((event) => (
                          <SelectItem key={event.bookingId} value={String(event.bookingId)}>
                            {event.title} #{event.bookingId}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                {availableEvents.length === 0 && (
                  <p className="text-sm text-muted-foreground">
                    Your upcoming bookings will appear here once events are available on your dashboard.
                  </p>
                )}

                {/* Feedback Type */}
                <div>
                  <Label htmlFor="feedback_type">Feedback Type</Label>
                  <Select 
                    value={formData.feedback_type} 
                    onValueChange={(value) => setFormData({...formData, feedback_type: value})}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(feedbackTypes).map(([key, type]) => (
                        <SelectItem key={key} value={key}>
                          {type.name} - {type.description}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Ratings */}
                <div className="space-y-4">
                  <h3 className="text-lg font-semibold">Ratings</h3>
                  
                  {Object.entries(ratingCategories).map(([key, category]) => (
                    <div key={key} className="space-y-2">
                      <Label>{category.name} {category.required && '*'}</Label>
                      <p className="text-sm text-gray-600">{category.description}</p>
                      {renderStarRating(
                        formData[key as keyof typeof formData] as number,
                        (rating) => setFormData({...formData, [key]: rating})
                      )}
                    </div>
                  ))}
                </div>

                {/* Title and Comment */}
                <div>
                  <Label htmlFor="title">Review Title (Optional)</Label>
                  <Input
                    id="title"
                    placeholder="Brief title for your review"
                    value={formData.title}
                    onChange={(e) => setFormData({...formData, title: e.target.value})}
                  />
                </div>

                <div>
                  <Label htmlFor="comment">Comment</Label>
                  <Textarea
                    id="comment"
                    placeholder="Share your detailed experience..."
                    rows={4}
                    value={formData.comment}
                    onChange={(e) => setFormData({...formData, comment: e.target.value})}
                    required={feedbackTypes[formData.feedback_type]?.requires_comment}
                  />
                </div>

                {/* Media Upload */}
                <div>
                  <Label>Photos/Videos (Optional, max 5 files)</Label>
                  <div className="mt-2">
                    <input
                      type="file"
                      multiple
                      accept="image/*,video/*"
                      onChange={handleFileUpload}
                      className="hidden"
                      id="media-upload"
                    />
                    <label
                      htmlFor="media-upload"
                      className="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 cursor-pointer"
                    >
                      <Upload className="h-4 w-4 mr-2" />
                      Upload Media
                    </label>
                  </div>

                  {mediaFiles.length > 0 && (
                    <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                      {mediaFiles.map((file, index) => (
                        <div key={index} className="relative">
                          <div className="aspect-square bg-gray-100 rounded-lg flex items-center justify-center">
                            {file.type.startsWith('image/') ? (
                              <img
                                src={URL.createObjectURL(file)}
                                alt={file.name}
                                className="w-full h-full object-cover rounded-lg"
                              />
                            ) : (
                              <Camera className="h-8 w-8 text-gray-400" />
                            )}
                          </div>
                          <button
                            type="button"
                            onClick={() => removeFile(index)}
                            className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600"
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                {/* Privacy Options */}
                <div className="space-y-4">
                  <div className="flex items-center space-x-2">
                    <Checkbox
                      id="is_anonymous"
                      checked={formData.is_anonymous}
                      onCheckedChange={(checked) => setFormData({...formData, is_anonymous: !!checked})}
                    />
                    <Label htmlFor="is_anonymous">Submit anonymously</Label>
                  </div>

                  <div className="flex items-center space-x-2">
                    <Checkbox
                      id="allow_public_display"
                      checked={formData.allow_public_display}
                      onCheckedChange={(checked) => setFormData({...formData, allow_public_display: !!checked})}
                    />
                    <Label htmlFor="allow_public_display">Allow public display of this review</Label>
                  </div>
                </div>

                <Button type="submit" disabled={isEventLoading(EVENT_LOADING_KEYS.FEEDBACK_SUBMIT)} className="w-full">
                  {isEventLoading(EVENT_LOADING_KEYS.FEEDBACK_SUBMIT) ? (
                    <>
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                      Submitting...
                    </>
                  ) : (
                    <>
                      <Send className="h-4 w-4 mr-2" />
                      Submit Feedback
                    </>
                  )}
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>

        {/* My Feedback Tab */}
        <TabsContent value="my-feedback" className="space-y-6">
          {isEventLoading(EVENT_LOADING_KEYS.FEEDBACK_LOAD) ? (
            <EventLoading pattern="SECTION" message="Loading your feedback..." />
          ) : myFeedback.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <div className="text-center py-8">
                  <MessageSquare className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Feedback Yet</h3>
                  <p className="text-gray-600 mb-4">You haven't submitted any feedback yet.</p>
                  <Button onClick={() => setActiveTab('submit')}>
                    Submit Your First Review
                  </Button>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-6">
              {myFeedback.map((feedback) => (
                <Card key={feedback.id}>
                  <CardContent className="pt-6">
                    <div className="flex justify-between items-start mb-4">
                      <div>
                        <h3 className="text-lg font-semibold text-gray-900">{feedback.event?.title || 'Event'}</h3>
                        <p className="text-sm text-gray-600">
                          {feedback.event?.start_date ? format(new Date(feedback.event.start_date), 'PPP') : 'Date TBD'} • {feedback.event?.venue?.name || 'Venue TBD'}
                        </p>
                      </div>
                      <div className="flex items-center space-x-2">
                        <Badge variant={
                          feedback.status === 'approved' ? 'default' :
                          feedback.status === 'pending' ? 'secondary' :
                          feedback.status === 'rejected' ? 'destructive' : 'outline'
                        }>
                          {feedback.status || 'pending'}
                        </Badge>
                        {feedback.is_featured && (
                          <Badge variant="outline">
                            <Heart className="h-3 w-3 mr-1" />
                            Featured
                          </Badge>
                        )}
                      </div>
                    </div>

                    {feedback.overall_rating && (
                      <div className="mb-3">
                        {renderStarRating(feedback.overall_rating)}
                      </div>
                    )}

                    {feedback.title && (
                      <h4 className="font-medium text-gray-900 mb-2">{feedback.title}</h4>
                    )}

                    {feedback.comment && (
                      <p className="text-gray-700 mb-4">{feedback.comment}</p>
                    )}

                    {feedback.media_attachments && feedback.media_attachments.length > 0 && (
                      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
                        {feedback.media_attachments.map((media, index) => (
                          <div key={index} className="aspect-square bg-gray-100 rounded-lg">
                            {media.type.startsWith('image/') ? (
                              <img
                                src={`/storage/${media.path}`}
                                alt={media.original_name}
                                className="w-full h-full object-cover rounded-lg"
                              />
                            ) : (
                              <div className="w-full h-full flex items-center justify-center">
                                <Camera className="h-8 w-8 text-gray-400" />
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )}

                    <div className="flex items-center justify-between text-sm text-gray-600">
                      <span>Submitted {feedback.created_at ? format(new Date(feedback.created_at), 'PPp') : 'Recently'}</span>
                      <div className="flex items-center space-x-4">
                        <span>{feedback.helpful_votes || 0} helpful votes</span>
                        <span>Type: {feedback.feedback_type || 'review'}</span>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Browse Reviews Tab */}
        <TabsContent value="browse" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Browse Event Reviews</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="mb-4">
                <Label htmlFor="event-select">Select Event to View Reviews</Label>
                <Select
                  value={selectedEventId ? String(selectedEventId) : 'none'}
                  onValueChange={(value) => setSelectedEventId(value === 'none' ? null : parseInt(value))}
                >
                  <SelectTrigger id="event-select">
                    <SelectValue placeholder="Select an event" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Select an event</SelectItem>
                    {availableEvents.map((event) => (
                      <SelectItem key={event.id} value={String(event.id)}>
                        {event.title}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {feedbackStats && (
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                  <div className="text-center">
                    <p className="text-2xl font-bold text-gray-900">{feedbackStats.total_reviews}</p>
                    <p className="text-sm text-gray-600">Total Reviews</p>
                  </div>
                  <div className="text-center">
                    <p className="text-2xl font-bold text-gray-900">
                      {feedbackStats.average_rating ? feedbackStats.average_rating.toFixed(1) : 'N/A'}
                    </p>
                    <p className="text-sm text-gray-600">Average Rating</p>
                  </div>
                  <div className="text-center">
                    <p className="text-2xl font-bold text-gray-900">
                      {feedbackStats.category_averages.venue ? feedbackStats.category_averages.venue.toFixed(1) : 'N/A'}
                    </p>
                    <p className="text-sm text-gray-600">Venue Rating</p>
                  </div>
                  <div className="text-center">
                    <p className="text-2xl font-bold text-gray-900">
                      {feedbackStats.category_averages.organization ? feedbackStats.category_averages.organization.toFixed(1) : 'N/A'}
                    </p>
                    <p className="text-sm text-gray-600">Organization</p>
                  </div>
                </div>
              )}

              {eventFeedback.length === 0 ? (
                <div className="text-center py-8">
                  <MessageSquare className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Reviews Yet</h3>
                  <p className="text-gray-600">
                    {selectedEventId ? 'This event has no reviews yet.' : 'Select an event to view reviews.'}
                  </p>
                </div>
              ) : (
                <div className="space-y-6">
                  {eventFeedback.map((feedback) => (
                    <Card key={feedback.id}>
                      <CardContent className="pt-6">
                        <div className="flex justify-between items-start mb-4">
                          <div className="flex items-center space-x-3">
                            <Avatar>
                              <AvatarFallback>
                                {feedback.is_anonymous ? '?' : 'U'}
                              </AvatarFallback>
                            </Avatar>
                            <div>
                              <p className="font-medium text-gray-900">
                                {feedback.is_anonymous ? 'Anonymous' : 'Customer'}
                              </p>
                              <p className="text-sm text-gray-600">
                                {feedback.created_at ? format(new Date(feedback.created_at), 'PPP') : 'Recently'}
                              </p>
                            </div>
                          </div>
                          {feedback.is_featured && (
                            <Badge variant="outline">
                              <Heart className="h-3 w-3 mr-1" />
                              Featured
                            </Badge>
                          )}
                        </div>

                        {feedback.overall_rating && (
                          <div className="mb-3">
                            {renderStarRating(feedback.overall_rating)}
                          </div>
                        )}

                        {feedback.title && (
                          <h4 className="font-medium text-gray-900 mb-2">{feedback.title}</h4>
                        )}

                        {feedback.comment && (
                          <p className="text-gray-700 mb-4">{feedback.comment}</p>
                        )}

                        {feedback.media_attachments && feedback.media_attachments.length > 0 && (
                          <div className="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
                            {feedback.media_attachments.map((media, index) => (
                              <div key={index} className="aspect-square bg-gray-100 rounded-lg">
                                {media.type.startsWith('image/') ? (
                                  <img
                                    src={`/storage/${media.path}`}
                                    alt={media.original_name}
                                    className="w-full h-full object-cover rounded-lg"
                                  />
                                ) : (
                                  <div className="w-full h-full flex items-center justify-center">
                                    <Camera className="h-8 w-8 text-gray-400" />
                                  </div>
                                )}
                              </div>
                            ))}
                          </div>
                        )}

                        <div className="flex items-center justify-between">
                          <div className="flex items-center space-x-4">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleVoteFeedback(feedback.id || 0, true)}
                            >
                              <ThumbsUp className="h-4 w-4 mr-1" />
                              Helpful ({feedback.helpful_votes || 0})
                            </Button>
                            <span className="text-sm text-gray-600">
                              {feedback.total_votes || 0} votes
                            </span>
                          </div>
                          <Badge variant="outline">
                            {feedback.feedback_type || 'review'}
                          </Badge>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default FeedbackSystem;