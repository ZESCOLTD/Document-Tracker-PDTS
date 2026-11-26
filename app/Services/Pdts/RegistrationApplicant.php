<?php

namespace App\Services\Pdts;

use App\Models\PdtsOffice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RegistrationApplicant
{
    public function resolve(User $user, ?PdtsOffice $office, Collection $directorates): array
    {
        $result = [
            'applicant' => [
                'name' => $user->name,
                'man_no' => $user->man_no,
                'job_title' => $user->job_title,
                'directorate' => $user->directorate,
                'division' => $user->division,
                'functional_section' => $user->functional_section,
            ],
            'directorate_id' => '', 'department_id' => '', 'office_id' => '', 'unit_source' => 'Manual selection required',
        ];
        if ($office?->isUsable()) {
            return array_replace($result, [
                'directorate_id' => (string) $office->owningDirectorate()->id,
                'department_id' => (string) ($office->department_id ?? ''),
                'office_id' => (string) $office->id,
                'unit_source' => 'Employee office assignment',
            ]);
        }

        $normalize = fn ($value) => Str::lower(Str::squish((string) $value));
        if ($normalize($user->directorate) === '') {
            return $result;
        }
        $matches = $directorates->filter(fn ($directorate) => $directorate->is_active
            && $normalize($directorate->name) === $normalize($user->directorate));
        if ($matches->count() !== 1) {
            return $result;
        }
        $directorate = $matches->first();
        $result['directorate_id'] = (string) $directorate->id;
        $result['unit_source'] = 'PHRIS profile name match';
        if ($normalize($user->division) !== '') {
            $departments = $directorate->departments->filter(fn ($department) => $department->is_active
                && (int) $department->directorate_id === (int) $directorate->id
                && $normalize($department->name) === $normalize($user->division));
            if ($departments->count() === 1) {
                $result['department_id'] = (string) $departments->first()->id;
            }
        }

        return $result;
    }
}
