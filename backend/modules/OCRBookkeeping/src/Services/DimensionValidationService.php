<?php

namespace Modules\OCRBookkeeping\Services;

use App\Models\Department;
use App\Models\Project;
use App\Models\Location;

/**
 * Dimension Validation Service
 * 
 * Validates dimension tags (departments, projects, locations)
 * Ensures dimensions exist and are active
 */
class DimensionValidationService
{
    /**
     * Validate dimensions array
     * 
     * @param array|null $dimensions
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(?array $dimensions): array
    {
        if (!$dimensions) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];

        // Validate department
        if (isset($dimensions['department_id'])) {
            $department = Department::active()->find($dimensions['department_id']);
            if (!$department) {
                $errors['department_id'] = 'Invalid or inactive department';
            }
        }

        // Validate project
        if (isset($dimensions['project_id'])) {
            $project = Project::active()->find($dimensions['project_id']);
            if (!$project) {
                $errors['project_id'] = 'Invalid or inactive project';
            }
        }

        // Validate location
        if (isset($dimensions['location_id'])) {
            $location = Location::active()->find($dimensions['location_id']);
            if (!$location) {
                $errors['location_id'] = 'Invalid or inactive location';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate and throw exception if invalid
     * 
     * @param array|null $dimensions
     * @return void
     * @throws \Exception
     */
    public function validateOrFail(?array $dimensions): void
    {
        $result = $this->validate($dimensions);

        if (!$result['valid']) {
            $errorMessages = implode(', ', $result['errors']);
            throw new \Exception("Dimension validation failed: {$errorMessages}");
        }
    }

    /**
     * Enrich dimensions with names for display
     * 
     * @param array|null $dimensions
     * @return array|null
     */
    public function enrichDimensions(?array $dimensions): ?array
    {
        if (!$dimensions) {
            return null;
        }

        $enriched = $dimensions;

        if (isset($dimensions['department_id'])) {
            $department = Department::find($dimensions['department_id']);
            if ($department) {
                $enriched['department_name'] = $department->name;
                $enriched['department_code'] = $department->code;
            }
        }

        if (isset($dimensions['project_id'])) {
            $project = Project::find($dimensions['project_id']);
            if ($project) {
                $enriched['project_name'] = $project->name;
                $enriched['project_code'] = $project->code;
            }
        }

        if (isset($dimensions['location_id'])) {
            $location = Location::find($dimensions['location_id']);
            if ($location) {
                $enriched['location_name'] = $location->name;
                $enriched['location_code'] = $location->code;
            }
        }

        return $enriched;
    }

    /**
     * Validate line item dimensions
     * 
     * @param array $lineItems
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateLineItemDimensions(array $lineItems): array
    {
        $errors = [];

        foreach ($lineItems as $index => $item) {
            if (isset($item['dimensions']) && is_array($item['dimensions'])) {
                $result = $this->validate($item['dimensions']);
                if (!$result['valid']) {
                    $errors["line_items.{$index}.dimensions"] = $result['errors'];
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
