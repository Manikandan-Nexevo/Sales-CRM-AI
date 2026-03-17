<?php

namespace App\Imports;

use App\Helpers\MailHelper;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;

class ContactsImport implements ToCollection
{
    protected $errors = [];
    protected $validRows = [];

    public function collection(Collection $rows)
    {
        $rows->shift(); // remove header row

        foreach ($rows as $index => $row) {

            if (!$row->filter()->count()) {
                continue;
            }

            // 🔍 DEBUG LOG (check laravel.log)
            Log::info('IMPORT ROW', ['row' => $row->toArray()]);

            $name = trim($row[0] ?? '-');
            $phone = $this->formatPhone($row[4] ?? null);
            $phoneAlt = $this->formatPhone($row[5] ?? null);
            $email = trim($row[6] ?? null);

            // 🔥 FIX: Handle index shift safely
            $assignedName = trim($row[14] ?? $row[15] ?? '');

            Log::info('ASSIGNED NAME DEBUG', [
                'row' => $index + 2,
                'assignedName' => $assignedName
            ]);

            $rowErrors = [];

            // Basic validation
            $validator = Validator::make([
                'email' => $email,
                'phone' => $phone
            ], [
                'email' => 'nullable|email',
                'phone' => 'required|digits_between:10,15'
            ]);

            if ($validator->fails()) {
                $rowErrors[] = implode(', ', $validator->errors()->all());
            }

            // Duplicate phone
            if ($phone && Contact::where('phone', $phone)->exists()) {
                $rowErrors[] = "Phone already exists";
            }

            // Duplicate email
            if ($email && Contact::where('email', $email)->exists()) {
                $rowErrors[] = "Email already exists";
            }

            // Default fallback → current user
            $userId = Auth::id();

            // 🔥 FIXED USER MATCHING
            if (!empty($assignedName)) {

                $user = User::whereRaw(
                    "LOWER(TRIM(name)) = ?",
                    [strtolower(trim($assignedName))]
                )->first();

                Log::info('USER MATCH RESULT', [
                    'assignedName' => $assignedName,
                    'user_found' => $user?->id
                ]);

                if ($user) {
                    $userId = $user->id;
                } else {
                    $rowErrors[] = "Assigned user not found: " . $assignedName;
                }
            }

            // If row has errors
            if (count($rowErrors)) {

                $this->errors[] = [
                    'row' => $index + 2,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'error' => implode(' | ', $rowErrors)
                ];

                continue;
            }

            // Save valid row
            $this->validRows[] = [
                'name' => $name,
                'designation' => $row[1] ?? null,
                'company' => $row[2] ?? '-',
                'industry' => $row[3] ?? null,
                'phone' => $phone,
                'phone_alt' => $phoneAlt,
                'email' => $email,
                'linkedin_url' => $row[7] ?? null,
                'status' => $row[8] ?? 'new',
                'priority' => $row[9] ?? 'medium',
                'source' => $row[10] ?? null,
                'location' => $row[11] ?? null,
                'company_size' => $row[12] ?? null,
                'notes' => $row[13] ?? null,
                'assigned_to' => $userId,
            ];
        }

        // Stop import if any errors
        if (count($this->errors)) {
            $this->sendErrorMail();
            return;
        }

        // Insert contacts
        foreach ($this->validRows as $data) {
            Contact::create($data);
        }

        $this->sendSuccessMail();
    }

    public function hasErrors()
    {
        return count($this->errors) > 0;
    }

    private function formatPhone($phone)
    {
        if (!$phone) return null;

        $phone = str_replace([' ', '+'], '', $phone);
        $phone = ltrim($phone, '0');

        if (!str_starts_with($phone, '91')) {
            $phone = '91' . $phone;
        }

        return $phone;
    }

    private function sendErrorMail()
    {
        $userEmail = Auth::user()->email;

        $body = "<h3>Upload Error Report</h3>";
        $body .= "<p>Import failed. No contacts were uploaded because errors were found.</p>";

        $body .= "<table border='1' cellpadding='8' cellspacing='0'>
                    <tr>
                        <th>Row</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Error</th>
                    </tr>";

        foreach ($this->errors as $error) {
            $body .= "<tr>
                        <td>{$error['row']}</td>
                        <td>{$error['name']}</td>
                        <td>{$error['email']}</td>
                        <td>{$error['phone']}</td>
                        <td>{$error['error']}</td>
                      </tr>";
        }

        $body .= "</table>";

        MailHelper::sendMail($userEmail, "Upload Error", $body, true);
    }

    private function sendSuccessMail()
    {
        $userEmail = Auth::user()->email;

        $count = count($this->validRows);

        $body = "<h3>Upload Success</h3>";
        $body .= "<p>{$count} contacts imported successfully.</p>";

        MailHelper::sendMail($userEmail, "Upload Success", $body, true);
    }
}
