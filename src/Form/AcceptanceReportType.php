<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AcceptanceReport;
use App\Enum\AcceptanceDecision;
use App\Enum\AcceptanceDocumentStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AcceptanceReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label) => ['label' => $label, 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'];
        $text = static fn (string $label, int $rows = 3, ?string $help = null) => ['label' => $label, 'required' => false, 'help' => $help, 'attr' => ['rows' => $rows]];

        $builder
            // 1. Identification
            ->add('documentTitle', TextType::class, ['label' => 'Titre du document', 'attr' => ['placeholder' => 'Digital Courtage - Finagora - Gestion Clients']])
            ->add('documentStatus', EnumType::class, [
                'class' => AcceptanceDocumentStatus::class,
                'label' => 'Statut du document',
                'expanded' => true,
                'choice_label' => static fn (AcceptanceDocumentStatus $s) => $s->label(),
            ])
            ->add('applicationName', TextType::class, ['label' => 'Projet / Application / Plan produit / Chantier'])
            ->add('code', TextType::class, ['label' => 'Code', 'required' => false])
            ->add('versionLabel', TextType::class, ['label' => 'Version'])
            ->add('mepDate', DateType::class, $date('Date MEP'))
            ->add('subject', TextareaType::class, $text('Objet de la recette', 2))
            ->add('acceptanceManagers', TextareaType::class, $text('Responsable(s) de la recette', 4, 'Un nom par ligne.'))
            ->add('deliveryManagers', TextareaType::class, $text('Responsable(s) de la livraison', 4, 'Un nom par ligne.'))
            // 2.1 Déroulement
            ->add('phases', CollectionType::class, [
                'label' => false,
                'entry_type' => AcceptancePhaseType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ])
            // 2.2 Périmètre
            ->add('scopeIntro', TextareaType::class, $text('Texte sous le périmètre', 4))
            ->add('outOfScope', TextareaType::class, $text('Hors périmètre de tests', 2))
            ->add('testCommentPrefix', TextType::class, [
                'label' => 'Préfixe des commentaires de tests Jira',
                'help' => 'Seuls les commentaires commençant par ce préfixe (casse et espaces autour du « : » ignorés) sont repris en 2.2.1. Actualisez depuis Jira après modification.',
            ])
            ->add('anomaliesComment', TextareaType::class, $text('Commentaire sur les anomalies', 2, 'Ex. : toutes avérées, corrigées et validées. Le nombre d\'anomalies est calculé automatiquement.'))
            // 3. Conclusion
            ->add('decision', EnumType::class, [
                'class' => AcceptanceDecision::class,
                'label' => 'Décision',
                'required' => false,
                'expanded' => true,
                'placeholder' => 'Non décidée',
                'choice_label' => static fn (AcceptanceDecision $d) => $d->label(),
            ])
            ->add('reserves', TextareaType::class, $text('Réserves', 3))
            ->add('comments', TextareaType::class, $text('Commentaires', 3))
            // 4. Signatures
            ->add('deliverySignatory', TextType::class, ['label' => 'Responsable de la livraison — nom', 'required' => false])
            ->add('deliverySignedAt', DateType::class, $date('Date'))
            ->add('acceptanceSignatory', TextType::class, ['label' => 'Responsable de la recette — nom', 'required' => false])
            ->add('acceptanceSignedAt', DateType::class, $date('Date'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AcceptanceReport::class]);
    }
}
