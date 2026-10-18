<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use Tests\TestCase;

/**
 * The customer's display name is their name — the company is a separate field
 * shown in the customer details, never substituted for or suffixed onto the
 * name.
 */
class CustomerDisplayNameTest extends TestCase
{
    public function test_company_customer_displays_its_name_not_the_registered_company(): void
    {
        $customer = new Customer([
            'type' => 'company',
            'name' => "W Gynae Women's Clinic",
            'company' => "Singapore Women's and Children's Medical Group Pte Ltd",
        ]);

        $this->assertSame("W Gynae Women's Clinic", $customer->display_name);
    }

    public function test_individual_customer_does_not_get_a_company_suffix(): void
    {
        $customer = new Customer([
            'type' => 'individual',
            'name' => 'Jane Tan',
            'company' => 'Acme Pte Ltd',
        ]);

        $this->assertSame('Jane Tan', $customer->display_name);
    }

    public function test_display_name_falls_back_to_company_when_name_is_blank(): void
    {
        $customer = new Customer([
            'type' => 'company',
            'name' => '',
            'company' => 'Acme Pte Ltd',
        ]);

        $this->assertSame('Acme Pte Ltd', $customer->display_name);
    }
}