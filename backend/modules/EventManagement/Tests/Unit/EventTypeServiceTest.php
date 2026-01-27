<?php

namespace Modules\EventManagement\Tests\Unit;

use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\EventTypeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventTypeServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventTypeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventTypeService();
        Cache::flush();
    }

    /** @test */
    public function it_can_get_all_active_event_types()
    {
        // Arrange
        EventType::create(['name' => 'Concert', 'slug' => 'concert', 'is_active' => true]);
        EventType::create(['name' => 'Meet-the-Artist', 'slug' => 'meet-the-artist', 'is_active' => true]);
        EventType::create(['name' => 'Inactive Type', 'slug' => 'inactive', 'is_active' => false]);

        // Act
        $activeTypes = $this->service->getAllActiveTypes();

        // Assert
        $this->assertCount(2, $activeTypes);
        $this->assertTrue($activeTypes->every(fn($type) => $type->is_active));
    }

    /** @test */
    public function it_caches_active_event_types()
    {
        // Arrange
        EventType::create(['name' => 'Concert', 'slug' => 'concert', 'is_active' => true]);

        // Act - First call should hit database
        $firstCall = $this->service->getAllActiveTypes();
        
        // Add a new type after first call
        EventType::create(['name' => 'New Type', 'slug' => 'new-type', 'is_active' => true]);
        
        // Second call should return cached result (only 1 type)
        $secondCall = $this->service->getAllActiveTypes();

        // Assert
        $this->assertCount(1, $firstCall);
        $this->assertCount(1, $secondCall); // Still 1 because of cache
    }

    /** @test */
    public function it_can_get_event_type_by_slug()
    {
        // Arrange
        $eventType = EventType::create([
            'name' => 'Concert',
            'slug' => 'concert'
        ]);

        // Act
        $result = $this->service->getTypeBySlug('concert');

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($eventType->id, $result->id);
        $this->assertEquals('concert', $result->slug);
    }

    /** @test */
    public function it_returns_null_for_non_existent_slug()
    {
        // Act
        $result = $this->service->getTypeBySlug('non-existent');

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    public function it_can_create_event_type()
    {
        // Arrange
        $data = [
            'name' => 'Workshop',
            'slug' => 'workshop',
            'description' => 'Educational workshop',
            'is_active' => true
        ];

        // Act
        $eventType = $this->service->createType($data);

        // Assert
        $this->assertInstanceOf(EventType::class, $eventType);
        $this->assertEquals('Workshop', $eventType->name);
        $this->assertEquals('workshop', $eventType->slug);
        $this->assertDatabaseHas('event_types', ['slug' => 'workshop']);
    }

    /** @test */
    public function it_validates_unique_name_on_create()
    {
        // Arrange
        EventType::create(['name' => 'Concert', 'slug' => 'concert']);

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->service->createType([
            'name' => 'Concert',
            'slug' => 'concert-2'
        ]);
    }

    /** @test */
    public function it_validates_unique_slug_on_create()
    {
        // Arrange
        EventType::create(['name' => 'Concert', 'slug' => 'concert']);

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->service->createType([
            'name' => 'Concert 2',
            'slug' => 'concert'
        ]);
    }

    /** @test */
    public function it_can_update_event_type()
    {
        // Arrange
        $eventType = EventType::create([
            'name' => 'Concert',
            'slug' => 'concert'
        ]);

        // Act
        $updated = $this->service->updateType($eventType->id, [
            'description' => 'Updated description'
        ]);

        // Assert
        $this->assertEquals('Updated description', $updated->description);
        $this->assertDatabaseHas('event_types', [
            'id' => $eventType->id,
            'description' => 'Updated description'
        ]);
    }

    /** @test */
    public function it_validates_unique_name_on_update()
    {
        // Arrange
        $eventType1 = EventType::create(['name' => 'Concert', 'slug' => 'concert']);
        $eventType2 = EventType::create(['name' => 'Workshop', 'slug' => 'workshop']);

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->service->updateType($eventType2->id, [
            'name' => 'Concert' // Trying to use existing name
        ]);
    }

    /** @test */
    public function it_validates_unique_slug_on_update()
    {
        // Arrange
        $eventType1 = EventType::create(['name' => 'Concert', 'slug' => 'concert']);
        $eventType2 = EventType::create(['name' => 'Workshop', 'slug' => 'workshop']);

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->service->updateType($eventType2->id, [
            'slug' => 'concert' // Trying to use existing slug
        ]);
    }

    /** @test */
    public function it_clears_cache_after_create()
    {
        // Arrange
        EventType::create(['name' => 'Concert', 'slug' => 'concert', 'is_active' => true]);
        
        // Prime the cache
        $this->service->getAllActiveTypes();

        // Act - Create new type
        $this->service->createType([
            'name' => 'Workshop',
            'slug' => 'workshop',
            'is_active' => true
        ]);

        // Get types again - should reflect new type
        $types = $this->service->getAllActiveTypes();

        // Assert
        $this->assertCount(2, $types);
    }

    /** @test */
    public function it_clears_cache_after_update()
    {
        // Arrange
        $eventType = EventType::create([
            'name' => 'Concert',
            'slug' => 'concert',
            'is_active' => true
        ]);
        
        // Prime the cache
        $this->service->getAllActiveTypes();

        // Act - Update to inactive
        $this->service->updateType($eventType->id, ['is_active' => false]);

        // Get active types again - should not include updated type
        $types = $this->service->getAllActiveTypes();

        // Assert
        $this->assertCount(0, $types);
    }
}
