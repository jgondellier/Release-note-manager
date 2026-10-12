<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Release;
use App\Enum\ReleaseStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ReleaseMetadataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $help = 'Syntaxe : ligne vide = paragraphe, « - » = puce, **gras**, `code`.';
        $builder
            ->add('title', TextType::class, ['label' => 'Titre éditorial'])
            ->add('releaseDate', DateType::class, [
                'label' => 'Date de mise en production',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
        if ($options['allow_status']) {
            $builder->add('status', EnumType::class, [
                'class' => ReleaseStatus::class,
                'label' => 'Statut',
                'choices' => ReleaseStatus::editorialChoices(),
                'choice_label' => static fn (ReleaseStatus $s) => $s->label(),
            ]);
        }
        $builder
            ->add('itIntro', TextareaType::class, ['label' => 'Introduction IT (périmètre technique)', 'required' => false, 'help' => $help, 'attr' => ['rows' => 4]])
            ->add('functionalIntro', TextareaType::class, ['label' => 'Présentation fonctionnelle de la version', 'required' => false, 'help' => $help, 'attr' => ['rows' => 4]])
            ->add('communicationTitle', TextType::class, ['label' => 'Titre de communication', 'required' => false, 'help' => 'À défaut, le titre éditorial est utilisé.'])
            ->add('communicationIntro', TextareaType::class, ['label' => 'Introduction de communication (courte)', 'required' => false, 'help' => $help, 'attr' => ['rows' => 3]])
            ->add('usefulLinks', TextareaType::class, [
                'label' => 'Liens utiles (tous formats)',
                'required' => false,
                'help' => 'Un lien par ligne : « Libellé | https://… ». Seules les URL http(s) sont publiées.',
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Release::class, 'allow_status' => true]);
        $resolver->setAllowedTypes('allow_status', 'bool');
    }
}
