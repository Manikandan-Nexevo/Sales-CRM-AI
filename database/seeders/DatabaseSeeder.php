<?php

namespace Database\Seeders;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create Admin
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@nexevo.in',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'target_calls_daily' => 50,
            'target_leads_monthly' => 20,
            'is_active' => true,
        ]);

        // Create Sales Reps
        $reps = [];
        $repData = [
            ['name' => 'Arjun Sharma', 'email' => 'arjun@nexevo.in'],
            ['name' => 'Priya Nair', 'email' => 'priya@nexevo.in'],
            ['name' => 'Rahul Mehta', 'email' => 'rahul@nexevo.in'],
        ];

        foreach ($repData as $data) {
            $reps[] = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make('password'),
                'role' => 'sales_rep',
                'target_calls_daily' => 50,
                'target_leads_monthly' => 10,
                'is_active' => true,
            ]);
        }

        // Sample contacts
        $contacts = [
            ['name' => 'Vikram Patel', 'company' => 'TechCorp India', 'designation' => 'CTO', 'phone' => '+919876543210', 'email' => 'vikram@techcorp.in', 'industry' => 'Technology', 'status' => 'hot', 'priority' => 'high', 'source' => 'LinkedIn'],
            ['name' => 'Sneha Reddy', 'company' => 'Fashion Plus', 'designation' => 'CEO', 'phone' => '+919876543211', 'email' => 'sneha@fashionplus.com', 'industry' => 'Retail', 'status' => 'qualified', 'priority' => 'high', 'source' => 'Referral'],
            ['name' => 'Amit Kumar', 'company' => 'Logistics Pro', 'designation' => 'IT Manager', 'phone' => '+919876543212', 'email' => 'amit@logisticspro.in', 'industry' => 'Logistics', 'status' => 'interested', 'priority' => 'medium', 'source' => 'Cold Call'],
            ['name' => 'Deepa Krishnan', 'company' => 'HealthFirst Clinic', 'designation' => 'Director', 'phone' => '+919876543213', 'email' => 'deepa@healthfirst.in', 'industry' => 'Healthcare', 'status' => 'contacted', 'priority' => 'medium', 'source' => 'Website'],
            ['name' => 'Suresh Iyer', 'company' => 'EduTech Solutions', 'designation' => 'Founder', 'phone' => '+919876543214', 'email' => 'suresh@edutech.in', 'industry' => 'Education', 'status' => 'new', 'priority' => 'low', 'source' => 'LinkedIn'],
            ['name' => 'Meera Joshi', 'company' => 'AutoDealer Premium', 'designation' => 'GM Operations', 'phone' => '+919876543215', 'email' => 'meera@autodealer.in', 'industry' => 'Automotive', 'status' => 'proposal', 'priority' => 'high', 'source' => 'Event'],
            ['name' => 'Kiran Rao', 'company' => 'RealEstate Plus', 'designation' => 'Tech Head', 'phone' => '+919876543216', 'email' => 'kiran@realestate.in', 'industry' => 'Real Estate', 'status' => 'hot', 'priority' => 'high', 'source' => 'Referral'],
            ['name' => 'Ananya Singh', 'company' => 'FinServ Corp', 'designation' => 'VP IT', 'phone' => '+919876543217', 'email' => 'ananya@finserv.in', 'industry' => 'Finance', 'status' => 'qualified', 'priority' => 'high', 'source' => 'LinkedIn'],
        ];

        $allUsers = array_merge([$admin], $reps);

        foreach ($contacts as $idx => $contactData) {
            $contact = Contact::create([
                ...$contactData,
                'assigned_to' => $allUsers[$idx % count($allUsers)]->id,
                'ai_score' => rand(40, 95),
                'linkedin_url' => 'https://linkedin.com/in/' . strtolower(str_replace(' ', '', $contactData['name'])),
            ]);

            // Add some call logs
            $statuses = ['connected', 'no_answer', 'connected', 'busy', 'connected'];
            $outcomes = ['Interested in demo', 'Follow up next week', 'Needs approval', 'Budget discussion', 'Sent proposal'];

            for ($i = 0; $i < rand(1, 4); $i++) {
                $user = $allUsers[$idx % count($allUsers)];
                CallLog::create([
                    'user_id' => $user->id,
                    'contact_id' => $contact->id,
                    'direction' => 'outbound',
                    'duration' => rand(30, 600),
                    'status' => $statuses[$i % count($statuses)],
                    'outcome' => $outcomes[$i % count($outcomes)],
                    'notes' => 'Discussed IT requirements. Client is evaluating vendors.',
                    'sentiment' => ['positive', 'neutral', 'positive'][$i % 3],
                    'interest_level' => rand(2, 5),
                    'created_at' => now()->subDays(rand(0, 14)),
                ]);
            }

            // Add follow-ups
            FollowUp::create([
                'user_id' => $allUsers[$idx % count($allUsers)]->id,
                'contact_id' => $contact->id,
                'type' => ['call', 'email', 'whatsapp'][$idx % 3],
                'subject' => 'Follow up on proposal - Nexevo IT Services',
                'scheduled_at' => now()->addDays(rand(0, 3)),
                'status' => 'pending',
            ]);
        }

        $this->command->info('✅ Nexevo Sales CRM seeded successfully!');
        $this->command->info('Admin: admin@nexevo.in / password');
        $this->command->info('Sales Rep: arjun@nexevo.in / password');
    }
}
