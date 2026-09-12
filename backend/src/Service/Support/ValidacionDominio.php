<?php

declare(strict_types=1);

namespace App\Service\Support;

use App\Exception\BusinessRuleException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ValidacionDominio
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function validar(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = $error->getPropertyPath().': '.$error->getMessage();
            }
            throw new BusinessRuleException(implode("\n", $messages));
        }
    }
}
