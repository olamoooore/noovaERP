import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
  Filter,
  SortAsc,
  AlertCircle,
  CheckCircle,
  Heart
} from 'lucide-react';
import { format } from 'date-fns';

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

interface FeedbackSubmission {
  id: number;
  event: {
    id: number;
    title: string;
    start_date: string;
    venue: {
      name: string;
    };
  };
  feedback_type: string;
  overall_rating?: number;
  venue_rating?: number;
  organization_rating?: number;
  value_rating?: number;
  title?: string;
  comment?: string;
  media_attachments?: Array<{
    type: string;
    original_name: string;
    path: string;
  }>;
  status: string;
  created_at: string;
  helpful_votes: number;
  total_votes: number;
  is_featured: boolean;
}

interface EventFeedback {
  id: number;
  customer_id: string;
  feedback_type: string;
  overall_rating?: number;
  venue_rating?: number;
  organization_rating?: number;
  value_rating?: number;
  title?: string;
  comment?: string;
  media_attachments?: Array<{
    type: string;
    original_name: string;
    path: string;
  }>;
  is_anonymous: boolean;
  helpful_votes: number;
  total_votes: number;
  is_featured: boolean;
  created_at: string;
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
  const [myFeedback, setMyFeedback] = useState<FeedbackSubmission[]>([]);
  const [eventFeedback, setEventFeedback] = useState<EventFeedback[]>([]);
  const [feedbackStats, setFeedbackStats] = useState<FeedbackStats | null>(null);
  const [loading, setLoading] = useState(false);
  const [selectedEventId, setSelectedEventId] = useState<number | null>(null);

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
  const [submitting, setSubmitting] = useState(false);

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
    try {
      const response = await fetch('/api/customer/feedback/types');
      const result = await response.json();
      if (result.success) {
        setFeedbackTypes(result.data);
      }
    } catch (err) {
      console.error('Failed to fetch feedback types:', err);
    }
  };

  const fetchRatingCategories = async () => {
    try {
      const response = await fetch('/api/customer/feedback/rating-categories');
      const result = await response.json();
      if (result.success) {
        setRatingCategories(result.data);
      }
    } catch (err) {
      console.error('Failed to fetch rating categories:', err);
    }
  };

  const fetchMyFeedback = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/customer/feedback/my-feedback', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'X-Tenant-ID': localStorage.getItem('tenantId') || '',
        },
      });
      const result = await response.json();
      if (result.success) {
        setMyFeedback(result.data);
      }
    } catch (err) {
      console.error('Failed to fetch my feedback:', err);
    } finally {
      setLoading(false);
    }
  };

  const fetchEventFeedback = async (eventId: number) => {
    try {
      const response = await fetch(`/api/events/${eventId}/feedback`);
      const result = await response.json();
      if (result.success) {
        setEventFeedback(result.data);
      }
    } catch (err) {
      console.error('Failed to fetch event feedback:', err);
    }
  };

  const fetchFeedbackStats = async (eventId: number) => {
    try {
      const response = await fetch(`/api/events/${eventId}/feedback/stats`);
      const result = await response.json();
      if (result.success) {
        setFeedbackStats(result.data);
      }
    } catch (err) {
      console.error('Failed to fetch feedback stats:', err);
    }
  };

  const handleSubmitFeedback = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    try {
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

      const response = await fetch('/api/customer/feedback', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'X-Tenant-ID': localStorage.getItem('tenantId') || '',
        },
        body: formDataToSend,
      });

      const result = await response.json();
      
      if (result.success) {
        alert('Feedback submitted successfully!');
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
      } else {
        alert(`Failed to submit feedback: ${result.message}`);
      }
    } catch (err) {
      console.error('Failed to submit feedback:', err);
      alert('Failed to submit feedback. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
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
    try {
      const response = await fetch(`/api/customer/feedback/${feedbackId}/vote`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'X-Tenant-ID': localStorage.getItem('tenantId') || '',
        },
        body: JSON.stringify({ is_helpful: isHelpful }),
      });

      if (response.ok) {
        // Refresh feedback data
        if (selectedEventId) {
          fetchEventFeedback(selectedEventId);
        }
      }
    } catch (err) {
      console.error('Failed to vote on feedback:', err);
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
        <TabsList className="grid w-full grid-cols-3">
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
                    <Input
                      id="event_id"
                      type="number"
                      placeholder="Event ID"
                      value={formData.event_id}
                      onChange={(e) => setFormData({...formData, event_id: e.target.value})}
                      required
                    />
                  </div>
                  <div>
                    <Label htmlFor="booking_id">Booking ID (Optional)</Label>
                    <Input
                      id="booking_id"
                      type="number"
                      placeholder="Booking ID"
                      value={formData.booking_id}
                      onChange={(e) => setFormData({...formData, booking_id: e.target.value})}
                    />
                  </div>
                </div>

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

                <Button type="submit" disabled={submitting} className="w-full">
                  {submitting ? (
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
          {loading ? (
            <div className="flex items-center justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
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
                        <h3 className="text-lg font-semibold text-gray-900">{feedback.event.title}</h3>
                        <p className="text-sm text-gray-600">
                          {format(new Date(feedback.event.start_date), 'PPP')} • {feedback.event.venue.name}
                        </p>
                      </div>
                      <div className="flex items-center space-x-2">
                        <Badge variant={
                          feedback.status === 'approved' ? 'default' :
                          feedback.status === 'pending' ? 'secondary' :
                          feedback.status === 'rejected' ? 'destructive' : 'outline'
                        }>
                          {feedback.status}
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
                      <span>Submitted {format(new Date(feedback.created_at), 'PPp')}</span>
                      <div className="flex items-center space-x-4">
                        <span>{feedback.helpful_votes} helpful votes</span>
                        <span>Type: {feedback.feedback_type}</span>
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
                <Input
                  id="event-select"
                  type="number"
                  placeholder="Enter Event ID"
                  value={selectedEventId || ''}
                  onChange={(e) => setSelectedEventId(e.target.value ? parseInt(e.target.value) : null)}
                />
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
                                {format(new Date(feedback.created_at), 'PPP')}
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
                              onClick={() => handleVoteFeedback(feedback.id, true)}
                            >
                              <ThumbsUp className="h-4 w-4 mr-1" />
                              Helpful ({feedback.helpful_votes})
                            </Button>
                            <span className="text-sm text-gray-600">
                              {feedback.total_votes} votes
                            </span>
                          </div>
                          <Badge variant="outline">
                            {feedback.feedback_type}
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