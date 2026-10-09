<?php

namespace App\Notifications;

use App\Models\EmployeeDocument;

/** Al colaborador: RRHH aprobó o rechazó un documento de su legajo */
class DocumentReviewed extends AppNotification
{
    public function __construct(public readonly EmployeeDocument $document) {}

    public function type(): string
    {
        return 'document.' . $this->document->status; // document.approved | document.rejected
    }

    public function title(): string
    {
        return $this->approved() ? 'Documento aprobado' : 'Documento rechazado';
    }

    public function body(): string
    {
        $label = $this->document->title ?: (EmployeeDocument::TYPES[$this->document->type] ?? 'Documento');

        return $this->approved()
            ? "RRHH aprobó tu documento \"{$label}\"."
            : "RRHH rechazó tu documento \"{$label}\". Motivo: {$this->document->rejection_reason}. Vuelve a subirlo desde la app.";
    }

    public function payload(): array
    {
        return [
            'document_id' => $this->document->id,
            'status'      => $this->document->status,
            'screen'      => 'documents',
        ];
    }

    private function approved(): bool
    {
        return $this->document->status === 'approved';
    }
}
