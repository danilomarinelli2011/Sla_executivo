<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

/**
 * Camada de compatibilidade de entrada dos controladores.
 *
 * O Zabbix 7.4/8.0 introduziu CController::setInputValidationMethod(), a constante
 * INPUT_VALIDATION_FORM e o formato de regras ['object', 'fields' => ...].
 *
 * Em frontends 6.4 e 7.0 esses membros nao existem: chama-los provoca
 * "Call to undefined method" (erro fatal do PHP => HTTP 500). Foi exatamente esse
 * o defeito corrigido na versao 1.5.3 do Relatorio de Disponibilidade, e o mesmo
 * cuidado vale aqui.
 *
 * A trait detecta a API disponivel em tempo de execucao e usa o caminho certo,
 * mantendo um unico pacote funcional em 6.4, 7.0, 7.2, 7.4 e 8.0.
 */
trait InputCompat {

	/**
	 * Verdadeiro quando o frontend possui a validacao de formularios (>= 7.4).
	 */
	protected function hasFormValidation(): bool {
		return method_exists($this, 'setInputValidationMethod');
	}

	/**
	 * Substitui o corpo original de init().
	 */
	protected function initInputCompat(): void {
		if ($this->hasFormValidation()) {
			$this->setInputValidationMethod(self::INPUT_VALIDATION_FORM);
		}

		$this->setPostContentType(self::POST_CONTENT_TYPE_FORM);
	}

	/**
	 * @param array<string, mixed>  $form_fields   Regras no formato 7.4/8.0.
	 * @param array<string, string> $legacy_rules  Regras equivalentes para o CNewValidator (6.4/7.0).
	 */
	protected function validateInputCompat(array $form_fields, array $legacy_rules): bool {
		return $this->hasFormValidation()
			? $this->validateInput(['object', 'fields' => $form_fields])
			: $this->validateInput($legacy_rules);
	}
}
