<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IssueTypeMapping;
use App\Entity\Project;
use App\Enum\SectionCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class IssueTypeMappingType extends AbstractType
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
            ->add('jiraIssueType', TextType::class, [
                'label' => 'Type de ticket Jira',
                'help' => 'Nom exact du type dans Jira (insensible à la casse), ex. Bug, Story, Tâche. « * » = tous les types non mappés.',
            ])
            ->add('sectionTitle', TextType::class, ['label' => 'Titre de la section'])
            ->add('category', EnumType::class, [
                'class' => SectionCategory::class,
                'label' => 'Catégorie (badge)',
                'choice_label' => static fn (SectionCategory $c) => $c->label(),
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Ordre d\'affichage',
                'help' => 'Les sections par type sont triées par ordre croissant, après les sections d\'Epic.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IssueTypeMapping::class]);
    }
}
