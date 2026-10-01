<?php

/*
|--------------------------------------------------------------------------
| Tracer-study questionnaires (FR-3)
|--------------------------------------------------------------------------
|
| One questionnaire per milestone after graduation: 6 months, 1 year, 3 years.
|
| THIS IS A STARTING POINT, NOT THE OFFICIAL INSTRUMENT. The spec asks for surveys "aligned to
| NCHE tracer-study expectations" but does not include the NCHE questionnaire. Have the QA
| Directorate compare these questions with NCHE's current guidance, edit them here, then run
|
|     php artisan sunates:sync-surveys
|
| Every change creates a NEW immutable version; answers already collected stay attached to the
| exact wording they were given for.
|
| Question format
|   key        unique within the survey; answers are stored under it
|   type       single_choice | multi_choice | text | long_text | number | scale | yes_no
|   label      the question as the alumnus reads it
|   required   true/false (default false)
|   options    [{value, label}, ...] for the two choice types
|   min / max  for number and scale (scale defaults to 1..5, with min_label / max_label)
|   show_if    ['key' => earlier question, 'in' => [values]]: only ask when that answer matches
|   maps_to    employment_status | further_study_status | started_business | ta_interest: copies the
|              answer into a column used by the outcome dashboards and the teaching-assistant flag
*/

$options = fn (array $pairs) => array_map(
    fn ($value, $label) => ['value' => $value, 'label' => $label],
    array_keys($pairs),
    array_values($pairs),
);

$working = ['employed', 'self_employed'];

$intro = 'Thank you for helping Soroti University understand how its graduates are doing. '
    .'It takes about two minutes. Your answers are used for reporting to the National Council for Higher Education '
    .'and for improving our programmes.';

$doingNow = [
    'key' => 'current_activity',
    'type' => 'single_choice',
    'label' => 'What are you doing now?',
    'required' => true,
    'maps_to' => 'employment_status',
    // Values match App\Enums\EmploymentStatus so the answer can update the alumnus's profile directly.
    'options' => $options([
        'employed' => 'Working for an employer',
        'self_employed' => 'Running my own business or working for myself',
        'unemployed' => 'Looking for work',
        'further_study' => 'Studying full time',
        'other' => 'Something else',
    ]),
];

$employment = [
    [
        'key' => 'employer_name',
        'type' => 'text',
        'label' => 'Name of your employer or business',
        'required' => true,
        'show_if' => ['key' => 'current_activity', 'in' => $working],
    ],
    [
        'key' => 'job_title',
        'type' => 'text',
        'label' => 'Your job title, or what you do',
        'required' => true,
        'show_if' => ['key' => 'current_activity', 'in' => $working],
    ],
    [
        'key' => 'sector',
        'type' => 'single_choice',
        'label' => 'Which sector is this in?',
        'show_if' => ['key' => 'current_activity', 'in' => $working],
        'options' => $options([
            'education' => 'Education',
            'health' => 'Health',
            'agriculture' => 'Agriculture and agribusiness',
            'trade' => 'Trade and business services',
            'public_service' => 'Government / public service',
            'ngo' => 'NGO / development',
            'manufacturing' => 'Manufacturing and construction',
            'ict' => 'ICT',
            'finance' => 'Banking and finance',
            'other' => 'Other',
        ]),
    ],
    [
        'key' => 'work_related_to_studies',
        'type' => 'single_choice',
        'label' => 'How closely is your work related to what you studied?',
        'required' => true,
        'show_if' => ['key' => 'current_activity', 'in' => $working],
        'options' => $options([
            'closely' => 'Closely related',
            'somewhat' => 'Somewhat related',
            'not_related' => 'Not related',
        ]),
    ],
    [
        'key' => 'monthly_income',
        'type' => 'single_choice',
        'label' => 'Roughly how much do you earn each month (UGX)? You can skip this.',
        'show_if' => ['key' => 'current_activity', 'in' => $working],
        'options' => $options([
            'under_300k' => 'Under 300,000',
            '300k_600k' => '300,000 to 600,000',
            '600k_1m' => '600,000 to 1,000,000',
            '1m_2m' => '1,000,000 to 2,000,000',
            'over_2m' => 'Over 2,000,000',
            'prefer_not' => 'Prefer not to say',
        ]),
    ],
];

$jobSearch = [
    [
        'key' => 'time_to_first_job',
        'type' => 'single_choice',
        'label' => 'How long did it take you to get your first job or income after graduating?',
        'required' => true,
        'show_if' => ['key' => 'current_activity', 'in' => ['employed', 'self_employed', 'unemployed']],
        'options' => $options([
            'before_graduation' => 'I had one before I graduated',
            'under_3_months' => 'Less than 3 months',
            '3_to_6_months' => '3 to 6 months',
            'over_6_months' => 'More than 6 months',
            'not_yet' => 'I have not had one yet',
        ]),
    ],
    [
        'key' => 'job_search_challenges',
        'type' => 'multi_choice',
        'label' => 'What has made it hard to find work? Choose all that apply.',
        'show_if' => ['key' => 'current_activity', 'in' => ['unemployed', 'other']],
        'options' => $options([
            'no_vacancies' => 'Few vacancies',
            'no_experience' => 'Employers want experience I do not have',
            'skills_mismatch' => 'My skills do not match what employers need',
            'no_connections' => 'I do not know the right people',
            'location' => 'Jobs are far from where I live',
            'other' => 'Something else',
        ]),
    ],
];

$furtherStudy = [
    [
        'key' => 'further_study',
        'type' => 'single_choice',
        'label' => 'Are you doing further study, or planning to?',
        'required' => true,
        'maps_to' => 'further_study_status',
        'options' => $options([
            'none' => 'No',
            'studying' => 'Yes, I am studying now',
            'planned' => 'Not yet, but I plan to',
        ]),
    ],
    [
        'key' => 'further_study_where',
        'type' => 'text',
        'label' => 'Which institution and course?',
        'show_if' => ['key' => 'further_study', 'in' => ['studying', 'planned']],
    ],
];

$enterprise = [
    [
        'key' => 'started_business',
        'type' => 'yes_no',
        'label' => 'Have you started your own business or income-generating activity since graduating?',
        'required' => true,
        'maps_to' => 'started_business',
    ],
    [
        'key' => 'business_workers',
        'type' => 'number',
        'label' => 'How many people work for you, not counting yourself?',
        'min' => 0,
        'max' => 100000,
        'show_if' => ['key' => 'started_business', 'in' => [true]],
    ],
];

$relevance = [
    [
        'key' => 'programme_prepared_me',
        'type' => 'scale',
        'label' => 'How well did your programme prepare you for work or further study?',
        'required' => true,
        'min' => 1,
        'max' => 5,
        'min_label' => 'Not at all',
        'max_label' => 'Very well',
    ],
    [
        'key' => 'skills_gaps',
        'type' => 'long_text',
        'label' => 'Which skills do you wish the programme had taught you?',
    ],
];

$careerProgress = [
    [
        'key' => 'career_progress',
        'type' => 'single_choice',
        'label' => 'Since graduating, have you been promoted or moved to a better role?',
        'show_if' => ['key' => 'current_activity', 'in' => ['employed']],
        'options' => $options([
            'promoted' => 'Yes, promoted where I work',
            'better_role' => 'Yes, I moved to a better role elsewhere',
            'no_change' => 'No change',
        ]),
    ],
];

$closing = [
    [
        'key' => 'ta_interest',
        'type' => 'yes_no',
        'label' => 'Would you be willing to help Soroti University as a teaching assistant or mentor to current students?',
        'maps_to' => 'ta_interest',
    ],
    [
        'key' => 'comments',
        'type' => 'long_text',
        'label' => 'Is there anything else you would like the university to know?',
    ],
];

$survey = fn (string $title, array ...$sections) => [
    'title' => $title,
    'definition' => [
        'intro' => $intro,
        'questions' => array_merge([$doingNow], ...$sections),
    ],
];

return [
    // Months after graduation => questionnaire.
    'milestones' => [
        6 => $survey('6-month graduate survey', $employment, $jobSearch, $furtherStudy, $enterprise, $closing),
        12 => $survey('1-year graduate survey', $employment, $jobSearch, $furtherStudy, $enterprise, $relevance, $closing),
        36 => $survey('3-year graduate survey', $employment, $careerProgress, $furtherStudy, $enterprise, $relevance, $closing),
    ],
];
