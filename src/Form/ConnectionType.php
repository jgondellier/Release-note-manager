<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExternalConnection;
use App\Enum\AuthenticationType;
use App\Enum\ConnectionProvider;
use App\Enum\DeploymentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le secret est un champ NON mappé, toujours vide à l'affichage : le secret
 * enregistré n'est jamais renvoyé au navigateur. Laisser vide = conserver l'actuel.
 */
final class ConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la connexion',
                'attr' => ['placeholder' => 'Ex. Jira Cloud CCS', 'maxlength' => 120],
            ])
            ->add('provider', EnumType::class, [
                'class' => ConnectionProvider::class,
                'label' => 'Outil',
                'choice_label' => static fn (ConnectionProvider $p) => $p->label(),
                'expanded' => true,
            ])
            ->add('deploymentType', EnumType::class, [
                'class' => DeploymentType::class,
                'label' => "Type d'instance",
                'choice_label' => static fn (DeploymentType $t) => $t->label(),
                'expanded' => true,
            ])
            ->add('baseUrl', UrlType::class, [
                'label' => 'URL de base',
                'default_protocol' => 'https',
                'help' => 'Jira Cloud : https://societe.atlassian.net — Confluence Cloud : https://societe.atlassian.net (ou …/wiki) — Server/DC : URL complète avec contexte, ex. https://confluence.interne/confluence',
                'attr' => ['placeholder' => 'https://', 'maxlength' => 255],
            ])
            ->add('authenticationType', EnumType::class, [
                'class' => AuthenticationType::class,
                'label' => "Méthode d'authentification",
                'choice_label' => static fn (AuthenticationType $t) => $t->label(),
                'help' => 'Cloud : Basic avec e-mail + API token. Server/DC : Personal Access Token (Bearer) si disponible, sinon Basic si autorisé par l\'instance.',
            ])
            ->add('username', TextType::class, [
                'label' => 'Identifiant ou e-mail',
                'required' => false,
                'help' => 'Obligatoire en Basic (e-mail du compte pour Cloud). Inutile en Bearer.',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 180],
            ])
            ->add('plainSecret', PasswordType::class, [
                'label' => $options['is_new'] ? 'Token / secret' : 'Nouveau token / secret',
                'mapped' => false,
                'required' => $options['is_new'],
                'always_empty' => true,
                'help' => $options['is_new']
                    ? 'Chiffré avant enregistrement. Il ne sera plus jamais affiché.'
                    : 'Laisser vide pour conserver le secret actuel (jamais affiché).',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $options['is_new']
                    ? [new Assert\NotBlank(message: 'Le token est obligatoire.'), new Assert\Length(max: 4000)]
                    : [new Assert\Length(max: 4000)],
            ])
            ->add('jiraEpicLinkField', TextType::class, [
                'label' => 'Champ « Epic Link » (Jira uniquement)',
                'required' => false,
                'help' => 'Ex. customfield_10014. Laissez vide : détection automatique lors du test sur Jira Server/DC. Jira Cloud utilise la relation parent.',
                'attr' => ['placeholder' => 'customfield_10014'],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Connexion active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExternalConnection::class,
            'is_new' => false,
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
