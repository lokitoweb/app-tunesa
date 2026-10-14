<?php

namespace App\Modules\CompanyDocs\Notifications;

use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\Core\Notifications\AppNotification;

/** Al personal del público: se publicó un documento (y si debe confirmarlo) */
class CompanyDocumentPublished extends AppNotification
{
    public function __construct(public readonly CompanyDocument $document) {}

    public function type(): string
    {
        return 'company_document.published';
    }

    public function title(): string
    {
        return $this->document->requires_acknowledgement ? 'Documento por leer y confirmar' : 'Nuevo documento de la empresa';
    }

    public function body(): string
    {
        $by = $this->document->requires_acknowledgement && $this->document->acknowledge_by
            ? ' Confírmalo antes del ' . $this->document->acknowledge_by->format('d/m/Y') . '.'
            : '';

        return "Se publicó \"{$this->document->title}\".{$by}";
    }

    public function payload(): array
    {
        return ['document_id' => $this->document->id, 'screen' => 'company_documents'];
    }
}
