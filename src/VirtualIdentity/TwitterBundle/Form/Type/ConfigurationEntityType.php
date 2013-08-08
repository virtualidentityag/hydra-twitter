<?php
namespace VirtualIdentity\TwitterBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class ConfigurationEntityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add(
                'apiRequests',
                'collection',
                array(
                    'type' => 'text',
                    'allow_add' => true,
                    'allow_delete' => true
                )
            )
            ->add('consumerKey')
            ->add('consumerSecret')
            ->add('token', 'text', array('required' => false))
            ->add('secret', 'text', array('required' => false))
            ->add('save', 'submit');
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'VirtualIdentity\TwitterBundle\Form\ConfigurationEntity',
        ));
    }

    public function getName()
    {
        return 'configurationEntity';
    }
}