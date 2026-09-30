<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Services\Import\AlumniImportService;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts::admin')]
#[Title('Import from spreadsheet')]
class ImportAlumni extends Component
{
    use AuthorizesStaff, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function updatedFile(): void
    {
        $this->report = null;
    }

    /** Dry run: shows exactly what would happen and saves nothing. */
    public function preview(AlumniImportService $import): void
    {
        $this->run($import, dryRun: true);
    }

    public function commit(AlumniImportService $import): void
    {
        $this->run($import, dryRun: false);

        if ($this->report !== null) {
            $this->file = null;
        }
    }

    private function run(AlumniImportService $import, bool $dryRun): void
    {
        $this->authorizeManager();

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'file.mimes' => 'Upload a CSV file (in Excel: File → Save As → CSV UTF-8).',
            'file.max' => 'The file is larger than 10 MB. Split it and import in parts.',
        ]);

        try {
            $this->report = $import->importFile($this->file->getRealPath(), $dryRun)->toArray();
        } catch (InvalidArgumentException $e) {
            $this->report = null;
            $this->addError('file', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.admin.import-alumni');
    }
}
