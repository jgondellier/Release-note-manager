<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExternalConnection;
use App\Entity\Project;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use App\Repository\ExternalConnectionRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $connectionLabel = static fn (ExternalConnection $c) => $c->getName() . ($c->isActive() ? '' : ' (inactive)');

        $builder
            ->add('name', TextType::class, ['label' => 'Nom du projet', 'attr' => ['maxlength' => 120]])
            ->add('code', TextType::class, [
                'label' => 'Code interne',
                'help' => 'Majuscules, chiffres, « _ » ou « - ». Ex. CCS, FINAGORA.',
                'attr' => ['maxlength' => 50, 'style' => 'text-transform:uppercase'],
            ])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('active', CheckboxType::class, ['label' => 'Projet actif', 'required' => false])
            ->add('jiraConnection', EntityType::class, [
                'class' => ExternalConnection::class,
                'label' => 'Connexion Jira',
                'placeholder' => '— Choisir —',
                'choice_label' => $connectionLabel,
                'query_builder' => static fn (ExternalConnectionRepository $r) => $r->createChoiceQueryBuilder(ConnectionProvider::Jira),
            ])
            ->add('jiraProjectKey', TextType::class, [
                'label' => 'Clé du projet Jira',
                'attr' => ['placeholder' => 'CCS', 'maxlength' => 50, 'style' => 'text-transform:uppercase'],
            ])
            ->add('confluenceCloudConnection', EntityType::class, [
                'class' => ExternalConnection::class,
                'label' => 'Connexion Confluence Cloud',
                'required' => false,
                'placeholder' => '— Aucune —',
                'choice_label' => $connectionLabel,
                'query_builder' => static fn (ExternalConnectionRepository $r) => $r->createChoiceQueryBuilder(ConnectionProvider::Confluence, DeploymentType::Cloud),
            ])
            ->add('confluenceCloudSpaceKey', TextType::class, ['label' => 'Clé d\'espace Cloud', 'required' => false, 'attr' => ['placeholder' => 'CCS']])
            ->add('confluenceCloudParentPageId', TextType::class, [
                'label' => 'ID de la page parente Cloud',
                'required' => false,
                'help' => 'Facultatif. Numéro visible dans l\'URL de la page (…/pages/123456/…).',
            ])
            ->add('confluenceCloudAcceptanceParentPageId', TextType::class, [
                'label' => 'ID de la page parente des PV de recette (Cloud)',
                'required' => false,
                'help' => 'Facultatif. Si renseigné, les PV de recette sont publiés sous cette page ; sinon sous la page de regroupement de la version.',
            ])
            ->add('confluenceServerConnection', EntityType::class, [
                'class' => ExternalConnection::class,
                'label' => 'Connexion Confluence Server / DC',
                'required' => false,
                'placeholder' => '— Aucune —',
                'choice_label' => $connectionLabel,
                'query_builder' => static fn (ExternalConnectionRepository $r) => $r->createChoiceQueryBuilder(ConnectionProvider::Confluence, DeploymentType::Server),
            ])
            ->add('confluenceServerSpaceKey', TextType::class, ['label' => 'Clé d\'espace Server / DC', 'required' => false])
            ->add('confluenceServerParentPageId', TextType::class, [
                'label' => 'ID de la page parente Server / DC',
                'required' => false,
                'help' => 'Facultatif. Paramètre pageId de l\'URL (viewpage.action?pageId=…).',
            ])
            ->add('confluenceServerAcceptanceParentPageId', TextType::class, [
                'label' => 'ID de la page parente des PV de recette (Server / DC)',
                'required' => false,
                'help' => 'Facultatif. Si renseigné, les PV de recette sont publiés sous cette page ; sinon sous la page de regroupement de la version.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Project::class]);
    }
}
