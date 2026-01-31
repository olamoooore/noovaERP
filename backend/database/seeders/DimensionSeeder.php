<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\Project;
use App\Models\Location;
use Carbon\Carbon;

class DimensionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = 1; // Assuming user ID 1 exists

        // Seed Departments
        $departments = [
            [
                'code' => 'SALES',
                'name' => 'Sales Department',
                'description' => 'Sales and business development team',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'MKTG',
                'name' => 'Marketing Department',
                'description' => 'Marketing and communications team',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'IT',
                'name' => 'IT Department',
                'description' => 'Information technology and systems',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'HR',
                'name' => 'Human Resources',
                'description' => 'Human resources and recruitment',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'FIN',
                'name' => 'Finance Department',
                'description' => 'Finance and accounting team',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'OPS',
                'name' => 'Operations',
                'description' => 'Operations and logistics',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ADMIN',
                'name' => 'Administration',
                'description' => 'General administration',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'RND',
                'name' => 'Research & Development',
                'description' => 'Research and product development',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($departments as $department) {
            Department::create($department);
        }

        // Seed Projects
        $projects = [
            [
                'code' => 'PROJ-2024-001',
                'name' => 'Website Redesign',
                'description' => 'Complete redesign of company website',
                'start_date' => Carbon::parse('2024-01-15'),
                'end_date' => Carbon::parse('2024-06-30'),
                'status' => 'active',
                'budget' => 50000.00,
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROJ-2024-002',
                'name' => 'ERP Implementation',
                'description' => 'Implementation of new ERP system',
                'start_date' => Carbon::parse('2024-03-01'),
                'end_date' => Carbon::parse('2024-12-31'),
                'status' => 'active',
                'budget' => 150000.00,
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROJ-2024-003',
                'name' => 'Marketing Campaign Q2',
                'description' => 'Q2 2024 marketing campaign',
                'start_date' => Carbon::parse('2024-04-01'),
                'end_date' => Carbon::parse('2024-06-30'),
                'status' => 'active',
                'budget' => 30000.00,
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROJ-2024-004',
                'name' => 'Office Expansion',
                'description' => 'Expansion of main office space',
                'start_date' => Carbon::parse('2024-02-01'),
                'end_date' => Carbon::parse('2024-08-31'),
                'status' => 'active',
                'budget' => 200000.00,
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROJ-2023-005',
                'name' => 'Product Launch Alpha',
                'description' => 'Launch of new product line Alpha',
                'start_date' => Carbon::parse('2023-09-01'),
                'end_date' => Carbon::parse('2024-01-31'),
                'status' => 'completed',
                'budget' => 75000.00,
                'is_active' => false,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROJ-2024-006',
                'name' => 'Customer Portal Development',
                'description' => 'Development of customer self-service portal',
                'start_date' => Carbon::parse('2024-05-01'),
                'end_date' => Carbon::parse('2024-11-30'),
                'status' => 'active',
                'budget' => 80000.00,
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($projects as $project) {
            Project::create($project);
        }

        // Seed Locations
        $locations = [
            [
                'code' => 'HQ-NYC',
                'name' => 'Headquarters - New York',
                'description' => 'Main headquarters office in New York City',
                'address' => '123 Business Ave, New York, NY 10001',
                'city' => 'New York',
                'state' => 'NY',
                'country' => 'USA',
                'postal_code' => '10001',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR-LA',
                'name' => 'Branch Office - Los Angeles',
                'description' => 'West coast branch office',
                'address' => '456 Commerce Blvd, Los Angeles, CA 90001',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'country' => 'USA',
                'postal_code' => '90001',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR-CHI',
                'name' => 'Branch Office - Chicago',
                'description' => 'Midwest regional office',
                'address' => '789 Industry St, Chicago, IL 60601',
                'city' => 'Chicago',
                'state' => 'IL',
                'country' => 'USA',
                'postal_code' => '60601',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'WH-NJ',
                'name' => 'Warehouse - New Jersey',
                'description' => 'Main distribution warehouse',
                'address' => '321 Logistics Way, Newark, NJ 07101',
                'city' => 'Newark',
                'state' => 'NJ',
                'country' => 'USA',
                'postal_code' => '07101',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR-LON',
                'name' => 'International Office - London',
                'description' => 'European headquarters',
                'address' => '10 Business Park, London, UK',
                'city' => 'London',
                'state' => null,
                'country' => 'UK',
                'postal_code' => 'EC1A 1BB',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR-SG',
                'name' => 'International Office - Singapore',
                'description' => 'Asia Pacific regional office',
                'address' => '50 Raffles Place, Singapore',
                'city' => 'Singapore',
                'state' => null,
                'country' => 'Singapore',
                'postal_code' => '048623',
                'is_active' => true,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BR-MIA-OLD',
                'name' => 'Former Branch - Miami',
                'description' => 'Closed Miami office',
                'address' => '999 Old Office Rd, Miami, FL 33101',
                'city' => 'Miami',
                'state' => 'FL',
                'country' => 'USA',
                'postal_code' => '33101',
                'is_active' => false,
                
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($locations as $location) {
            Location::create($location);
        }

        $this->command->info('Dimensions seeded successfully!');
        $this->command->info('Created ' . count($departments) . ' departments');
        $this->command->info('Created ' . count($projects) . ' projects');
        $this->command->info('Created ' . count($locations) . ' locations');
    }
}
