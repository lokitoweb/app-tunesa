<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Recorrido en navegador: los mensajes de filas de listas decían "Falta lessons.0.title." */
class ListFieldMessagesTest extends TestCase
{
    public function test_list_fields_have_readable_names_with_their_row_number(): void
    {
        app()->setLocale('es');
        $errors = Validator::make(
            ['lessons' => [['title' => 'ok'], ['title' => '']], 'items' => [['quantity' => '']], 'bank_accounts' => [['bank' => '']]],
            ['lessons.*.title' => 'required', 'items.*.quantity' => 'required', 'bank_accounts.*.bank' => 'required'],
        )->errors();

        $this->assertSame('Falta el título de la lección 2.', $errors->first('lessons.1.title'));
        $this->assertSame('Falta la cantidad de la línea 1.', $errors->first('items.0.quantity'));
        $this->assertSame('Falta el banco de la cuenta 1.', $errors->first('bank_accounts.0.bank'));
        $this->assertStringNotContainsString('.0.', implode(' ', $errors->all()));
    }
}
