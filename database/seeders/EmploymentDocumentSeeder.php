<?php

namespace Database\Seeders;

use App\Models\EmploymentDocument;
use Illuminate\Database\Seeder;

class EmploymentDocumentSeeder extends Seeder
{
    /**
     * Seed the employment document checklist.
     */
    public function run(): void
    {
        $documents = [
            [
                'document_code' => 'TIN',
                'document_name' => 'Tax Identification Number (TIN)',
                'category' => 'Government',
                'description' => 'TIN verification or an applicable BIR document.',
            ],
            [
                'document_code' => 'SSS',
                'document_name' => 'SSS Number',
                'category' => 'Government',
                'description' => 'SSS number verification or supporting document.',
            ],
            [
                'document_code' => 'PHILHEALTH',
                'document_name' => 'PhilHealth Number',
                'category' => 'Government',
                'description' => 'PhilHealth membership or number verification.',
            ],
            [
                'document_code' => 'PAGIBIG',
                'document_name' => 'Pag-IBIG (HDMF) Number',
                'category' => 'Government',
                'description' => 'Pag-IBIG or HDMF membership number verification.',
            ],
            [
                'document_code' => 'NBI_CLEARANCE',
                'document_name' => 'NBI Clearance',
                'category' => 'Clearance',
                'description' => 'Valid NBI clearance submitted by the employee.',
            ],
            [
                'document_code' => 'POLICE_CLEARANCE',
                'document_name' => 'Police Clearance',
                'category' => 'Clearance',
                'description' => 'Valid police clearance submitted by the employee.',
            ],
            [
                'document_code' => 'PSA_BIRTH_CERTIFICATE',
                'document_name' => 'PSA Birth Certificate',
                'category' => 'Personal',
                'description' => 'Official birth certificate issued by the Philippine Statistics Authority.',
            ],
            [
                'document_code' => 'VALID_GOVERNMENT_ID',
                'document_name' => 'Valid Government ID',
                'category' => 'Identification',
                'description' => 'A current government-issued photo identification document.',
            ],
            [
                'document_code' => 'TRANSCRIPT_OF_RECORDS',
                'document_name' => 'Transcript of Records',
                'category' => 'Education',
                'description' => 'Official academic transcript from the employee\'s school or university.',
            ],
            [
                'document_code' => 'DIPLOMA',
                'document_name' => 'Diploma',
                'category' => 'Education',
                'description' => 'Diploma supporting the employee\'s declared educational attainment.',
            ],
            [
                'document_code' => 'CERTIFICATE_OF_EMPLOYMENT',
                'document_name' => 'Certificate of Employment (COE)',
                'category' => 'Employment',
                'description' => 'Certificate from previous employers, when applicable, proving the employee\'s work history.',
            ],
            [
                'document_code' => 'PREVIOUS_EMPLOYER_CLEARANCE',
                'document_name' => 'Clearance from Previous Employer',
                'category' => 'Employment',
                'description' => 'Confirms that the employee has no outstanding liabilities with a previous employer.',
            ],
            [
                'document_code' => 'PEME',
                'document_name' => 'Pre-Employment Medical Examination (PEME)',
                'category' => 'Medical',
                'description' => 'Medical examination from a company-accredited clinic, typically including a chest X-ray, urine test, and physical exam.',
            ],
        ];

        foreach ($documents as $index => $document) {
            EmploymentDocument::updateOrCreate(
                ['document_code' => $document['document_code']],
                [...$document, 'sort_order' => $index + 1],
            );
        }
    }
}
