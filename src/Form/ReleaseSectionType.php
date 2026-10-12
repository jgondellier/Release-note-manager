<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ReleaseSection;
use App\Enum\SectionCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Wording éditorial d'une section. Les champs sont regroupés par format dans le template.
 */
final class ReleaseSectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = static fn (string $label, int $rows = 4, ?string $help = null) => [
            'label' => $label,
            'required' => false,
            'help' => $help,
            'attr' => ['rows' => $rows, 'data-preview-source' => '1'],
        ];

        $builder
            // Commun
            ->add('title', TextType::class, ['label' => 'Titre (IT et valeur par défaut)'])
            ->add('category', EnumType::class, [
                'class' => SectionCategory::class,
                'label' => 'Catégorie',
                'choice_label' => static fn (SectionCategory $c) => $c->label(),
            ])
            ->add('description', TextareaType::class, $text('Descriptif commun', 3, 'Utilisé par défaut en Fonctionnel et Communication si leurs textes sont vides.'))
            ->add('impact', TextareaType::class, $text('Impact utilisateur / métier', 3))
            ->add('actionRequired', TextareaType::class, $text('Action à effectuer', 2, 'Repris dans l\'encadré « Actions à prévoir ».'))
            ->add('audience', TextType::class, ['label' => 'Audience / parcours concernés', 'required' => false])
            ->add('links', TextareaType::class, $text('Liens utiles', 2, 'Un lien par ligne : « Libellé | https://… ».'))
            // IT
            ->add('itContent', TextareaType::class, $text('Contenu technique', 6))
            ->add('risks', TextareaType::class, $text('Risques et points de vigilance', 3, 'Affiché uniquement s\'il est renseigné.'))
            ->add('validation', TextareaType::class, $text('Tests et validations réalisés', 3, 'Affiché uniquement s\'il est renseigné.'))
            // Fonctionnel
            ->add('functionalTitle', TextType::class, ['label' => 'Titre fonctionnel', 'required' => false])
            ->add('functionalContent', TextareaType::class, $text('Wording fonctionnel', 6, 'Parcours, règles de gestion, impacts métier…'))
            ->add('functionalShowTickets', CheckboxType::class, ['label' => 'Lister les tickets (sans clé Jira) en fonctionnel', 'required' => false])
            // Communication
            ->add('communicationTitle', TextType::class, ['label' => 'Titre de communication', 'required' => false])
            ->add('communicationContent', TextareaType::class, $text('Wording de communication', 5, 'Phrases courtes, bénéfices utilisateurs.'))
            ->add('deliveryConfirmed', CheckboxType::class, [
                'label' => 'Livraison confirmée',
                'required' => false,
                'help' => 'Sans confirmation, la section n\'est présentée comme disponible que si tous ses tickets sont terminés dans Jira.',
            ])
            // Visibilité
            ->add('visibleIt', CheckboxType::class, ['label' => 'Visible en IT', 'required' => false])
            ->add('visibleFunctional', CheckboxType::class, ['label' => 'Visible en Fonctionnel', 'required' => false])
            ->add('visibleCommunication', CheckboxType::class, ['label' => 'Visible en Communication', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReleaseSection::class]);
    }
}
