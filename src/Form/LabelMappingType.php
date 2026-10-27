<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LabelMapping;
use App\Entity\Project;
use App\Enum\SectionCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LabelMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('project', EntityType::class, [
                'class' => Project::class,
                'label' => 'Périmètre',
                'required' => false,
                'placeholder' => 'Tous les projets (règle globale)',
                'choice_label' => 'name',
            ])
            ->add('label', TextType::class, [
                'label' => 'Étiquette Jira (mot-clé)',
                'help' => 'Telle qu\'elle apparaît dans le champ « Étiquettes » des tickets, ex. gestion-client. Casse ignorée.',
            ])
            ->add('title', TextType::class, [
                'label' => 'Phrase lisible (titre de la section)',
                'required' => false,
                'help' => 'Ex. « Évolutions de la gestion des clients ». Reste modifiable ensuite dans chaque release.',
            ])
            ->add('category', EnumType::class, [
                'class' => SectionCategory::class,
                'label' => 'Catégorie (badge)',
                'required' => false,
                'placeholder' => 'Automatique (type majoritaire des tickets)',
                'choice_label' => static fn (SectionCategory $c) => $c->label(),
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Priorité / ordre',
                'help' => 'Plus petit = prioritaire quand un ticket porte plusieurs étiquettes, et section affichée plus haut.',
            ])
            ->add('ignored', CheckboxType::class, [
                'label' => 'Ignorer cette étiquette pour le regroupement',
                'required' => false,
                'help' => 'Pour les étiquettes techniques (ex. release, sprint-12) qui ne doivent pas créer de section.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelMapping::class]);
    }
}
