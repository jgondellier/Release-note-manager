<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Ligne « Jalon / Phase » du déroulement de la recette (données tableau, dates au format Y-m-d).
 */
final class AcceptancePhaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label) => ['label' => $label, 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'Y-m-d'];
        $builder
            ->add('phase', TextType::class, ['label' => 'Jalon / Phase', 'required' => false, 'attr' => ['placeholder' => 'Tests système']])
            ->add('plannedStart', DateType::class, $date('Prévu du'))
            ->add('plannedEnd', DateType::class, $date('au'))
            ->add('actualStart', DateType::class, $date('Réel du'))
            ->add('actualEnd', DateType::class, $date('au'))
            ->add('environment', TextType::class, ['label' => 'Environnement(s)', 'required' => false, 'attr' => ['placeholder' => 'REC02, PPROD…']])
            ->add('comment', TextType::class, ['label' => 'Commentaire', 'required' => false]);
    }
}
