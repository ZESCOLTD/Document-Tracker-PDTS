<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\IpaPhrisView;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(array $input): User
    {
        $isZesco = !empty($input['man_no']);

        // ----------------------------
        // STEP 1: VERIFY EMPLOYEE FIRST
        // ----------------------------
        if ($isZesco) {
            $userData = $this->verifyZesco($input);

            // Override with trusted system data
            $input['name'] = $userData['name'];
            $input['email'] = $userData['staff_email'];
            $input['directorate'] = $userData['directorate'] ?? null;
            $input['job_title'] = $userData['job_title'] ?? null;
        }

        // ----------------------------
        // STEP 2: VALIDATION (AFTER OVERRIDE)
        // ----------------------------
        $rules = [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ];

        if ($isZesco) {
            $rules['man_no'] = ['required', Rule::unique('users')];
        }

        $rules['email'] = ['required', 'email', Rule::unique('users')];

        Validator::make($input, $rules)->validate();

        // ----------------------------
        // STEP 3: CREATE USER
        // ----------------------------
        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => bcrypt($input['password']),
            'man_no' => $input['man_no'] ?? null,
            'nrc' => $input['nrc'] ?? null,
            'directorate' => $input['directorate'] ?? null,
            'job_title' => $input['job_title'] ?? null,
            'grade' => $input['grade'] ?? null,
            'dob' => $input['dob'] ?? null,
            'function_section_code' => $input['function_section_code'] ?? null,
            'functional_section' => $input['functional_section'] ?? null,
            'bu_code' => $input['bu_code'] ?? null,
            'bu_name' => $input['bu_name'] ?? null,
            'cc_code' => $input['cc_code'] ?? null,
            'cc_name' => $input['cc_name'] ?? null,
        ]);
    }

    /**
     * Verify employee against PHRIS
     */
    public function verifyZesco(array $input): array
    {
        if (empty($input['man_no']) || empty($input['email'])) {
            throw ValidationException::withMessages([
                'man_no' => 'Man Number and Email are required for verification.',
            ]);
        }

        $response = $this->fetchZescoData($input['man_no']);

        if (!$response['success']) {
            throw ValidationException::withMessages([
                'man_no' => 'No matching employee record found.',
            ]);
        }

        $phrisEmail = trim(strtolower($response['user']['staff_email'] ?? ''));
        $inputEmail = trim(strtolower($input['email'] ?? ''));

        if ($phrisEmail === '' || $inputEmail === '' || $phrisEmail !== $inputEmail) {
            throw ValidationException::withMessages([
                'man_no' => 'Email mismatch with employee record.',
            ]);
        }


        return $response['user'];
    }

    /**
     * Fetch employee data from PHRIS view
     */
    protected function fetchZescoData(string $man_no): array
    {
        $data = IpaPhrisView::where('con_per_no', $man_no)->first();

        if (!$data) {
            return ['success' => false];
        }
        Log::debug('Registration attempt', ['input' => $data]);
        return [
            'success' => true,
            'user' => [
                'man_no' => $data->con_per_no,
                'name' => $data->name,
                'staff_email' => $data->staff_email,
                'directorate' => $data->directorate,
                'job_title' => $data->job_title,
            ],
        ];
    }

}
