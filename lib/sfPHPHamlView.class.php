<?php

/**
 * sfSPHPHamlView
 *
 * @package sfPHPHamlPlugin
 * @subpackage lib
 * @author Jesse Badwal <jesse@insaini.com>
 **/
class sfPHPHamlView extends sfPHPView {

    private $doctype = "XHTML 1.0 Strict";
    private $template_extension = ".haml";
    
    protected static $log;
    protected static $cache;
    
    protected $namespace = null;
    protected $id = null;
  
    /**
     * SfHAML Instance
     *
     * @var sfSmarty
     */
    protected static $haml = null;
    
  /**
    * Configures template.
    *
    * @return void
    */
   public function configure()
    {
        // store our current view
        $this->context->set('view_instance', $this);
    
        // require our configuration
        require($this->context->getConfigCache()->checkConfig('modules/'.$this->moduleName.'/config/view.yml'));
    
        // set template directory
        if ( ! $this->decoratorDirectory) {
            $decoratorDirs = $this->context->getConfiguration()->getDecoratorDirs();
            $this->decoratorDirectory = $decoratorDirs[0];
        }
    
        $this->doctype = sfConfig::get('app_sfHamlView_doctype', $this->doctype);
        $this->template_extension = sfConfig::get('app_sfHamlView_template_extension', $this->template_extension);
        $this->setExtension($this->template_extension);
        
        parent::configure();

    }
    
    /**
    * sfPHPHamlView::initialize()
    * This method is used instead of sfPHPView::initialze
    *
    * @param mixed $context
    * @param mixed $moduleName
    * @param mixed $actionName
    * @param mixed $viewName
    * @return
    **/
    public function initialize($context, $moduleName, $actionName, $viewName)
    {
        self::$haml = HamlParser::getInstance();
        
        $this->doctype = sfConfig::get('app_sfHamlView_doctype', $this->doctype);
        self::$haml->setDoctype($this->doctype);
        
        $this->template_extension = sfConfig::get('app_sfHamlView_template_extension', $this->template_extension);
        $this->setExtension($this->template_extension);
        
        // Call the parent method of sfPHPView
        parent::initialize($context, $moduleName, $actionName, $viewName);
        
        $this->namespace = $moduleName.'/'.$actionName;
        
        $options = array(
          'cache_dir' => sfConfig::get('sf_cache_dir').'/haml',
          'lifetime' => 0,
          'prefix' => $this->namespace
        );
        self::$cache = new sfFileCache($options);
        
        if (sfConfig::get('sf_logging_enabled'))
        {
          $this->dispatcher->notify(new sfEvent($this, 'application.log', array('{sfPHPHamlView} is used for rendering')));
        }
        
        return true;
    }


    /**
     * sfSmartyView::getEngine()
     * returns the sfSmarty instance
     *
     * @return sfSmarty instance
     */
    public function getEngine()
    {
        return self::$haml;
    }

    /**
     * sfPHPHamlView::preRenderCheck()
     *
     * Does some logic to allow the use of both
     * .php and smarty template files
     *
     * @see sfView::preRenderCheck()
     **/
    protected function preRenderCheck()
    {
        try {
            parent::preRenderCheck();
        } 
        catch (sfRenderException $e) {
            
            $template = str_replace($this->getExtension(), '.haml', $this->getTemplate());
            
            $this->setTemplate($template);
            $this->setExtension('.haml');
            
            parent::configure();
            
            if (!is_readable($this->decoratorTemplate)) {
                $this->decoratorTemplate = str_replace($this->template_extension, '.php', $this->decoratorTemplate);
            }
            
            parent::preRenderCheck();
            
        }
        
    }

    /**
    * sfPHPHamlView::renderFile()
    * this method is used instead of sfPHPView::renderFile()
    *
    * @param mixed $file
    * @return
    * @access protected
    **/
    protected function renderFile($file)
    {
        // TODO: We need to solve the problem with creating too much I/O.
        //           The internals of the cache files hava changed
        if (substr($file, -1 * strlen($this->template_extension)) != $this->template_extension)
        {
          // do not process the template file if not with the right extension
        }
        elseif (!self::$cache->has($this->id))
        {
          $data = self::$haml->parse(file_get_contents($file), 'PHP');
          self::$cache->set($this->id, $data);
          $file = tempnam(null, $this->namespace);
          file_put_contents($file, $data);
        }
        else
        {
          self::$cache->get($this->id);
          $file = tempnam($this->prefix);
          file_put_contents($file, $data);
        }
        
        ProjectConfiguration::getApplicationConfiguration('frontend', 'dev', true)->loadHelpers('Haml');
    
        return parent::renderFile($file);
    }

  public function __destruct()
  {
    if (sfConfig::get('sf_debug')) {
      self::$cache->remove($this->id);
    }
  }

}
