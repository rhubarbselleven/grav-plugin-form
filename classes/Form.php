<?php
namespace Grav\Plugin\Form;

use Grav\Common\Config\Config;
use Grav\Common\Data\Data;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\ValidationException;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Inflector;
use Grav\Common\Language\Language;
use Grav\Common\Page\Page;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Framework\Form\FormFlashFile;
use Grav\Framework\Form\Interfaces\FormInterface;
use Grav\Framework\Form\Traits\FormTrait;
use RocketTheme\Toolbox\ArrayTraits\NestedArrayAccessWithGetters;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class Form
 * @package Grav\Plugin\Form
 *
 * @property string $id
 * @property string $uniqueid
 * @property-read string $name
 * @property-read string $noncename
 * @property-read $string nonceaction
 * @property-read string $action
 * @property-read Data $data
 * @property-read array $files
 * @property-read Data $value
 * @property-read array $errors
 * @property-read array $fields
 * @property-read Blueprint $blueprint
 * @property-read Page $page
 */
class Form implements FormInterface, \ArrayAccess
{
    use NestedArrayAccessWithGetters {
        NestedArrayAccessWithGetters::get as private traitGet;
        NestedArrayAccessWithGetters::set as private traitSet;
    }
    use FormTrait {
        FormTrait::reset as private traitReset;
        FormTrait::doSerialize as private doTraitSerialize;
        FormTrait::doUnserialize as private doTraitUnserialize;
    }

    public const BYTES_TO_MB = 1048576;

    /**
     * @var string
     */
    public $message;

    /**
     * @var int
     */
    public $response_code;

    /**
     * @var string
     */
    public $status = 'success';

    /**
     * @var array
     */
    protected $header_data = [];

    /**
     * @var array
     */
    protected $rules = [];

    /**
     * Form header items
     *
     * @var array $items
     */
    protected $items = [];

    /**
     * All the form data values, including non-data
     *
     * @var Data $values
     */
    protected $values;

    /**
     * The form page route
     *
     * @var string $page
     */
    protected $page;

    /**
     * Create form for the given page.
     *
     * @param Page $page
     * @param string|int|null $name
     * @param array|null $form
     */
    public function __construct(Page $page, $name = null, $form = null)
    {
        $this->nestedSeparator = '/';

        $this->page = $page->route();

        $header = $page->header();
        $this->rules = $header->rules ?? [];
        $this->header_data = $header->data ?? [];

        if ($form) {
            // If form is given, use it.
            $this->items = $form;
        } else {
            // Otherwise get all forms in the page.
            $forms = $page->forms();
            if ($name) {
                // If form with given name was found, use that.
                $this->items = $forms[$name] ?? [];
            } else {
                // Otherwise pick up the first form.
                $this->items = reset($forms) ?: [];
                $name = key($forms);
            }
        }

        // Add form specific rules.
        if (!empty($this->items['rules']) && \is_array($this->items['rules'])) {
            $this->rules += $this->items['rules'];
        }

        // Set form name if not set.
        if ($name && !\is_int($name)) {
            $this->items['name'] = $name;
        } elseif (empty($this->items['name'])) {
            $this->items['name'] = $page->slug();
        }

        // Set form id if not set.
        if (empty($this->items['id'])) {
            $this->items['id'] = Inflector::hyphenize($this->items['name']);
        }
        if (empty($this->items['uniqueid'])) {
            $this->items['uniqueid'] = Utils::generateRandomString(20);
        }

        if (empty($this->items['nonce']['name'])) {
            $this->items['nonce']['name'] = 'form-nonce';
        }

        if (empty($this->items['nonce']['action'])) {
            $this->items['nonce']['action'] = 'form';
        }

        // Initialize form properties.
        $this->name = $this->items['name'];
        $this->setId($this->items['id']);
        $this->setUniqueId($this->items['uniqueid']);
        $this->errors = [];
        $this->submitted = false;

        // Remember form state.
        $flash = $this->getFlash();
        $data = $flash->exists() ? $flash->getData() : $this->header_data;

        // Reset and initialize the form
        $this->setAllData($data);
        $this->values = new Data();

        // Fire event
        $grav = Grav::instance();
        $grav->fireEvent('onFormInitialized', new Event(['form' => $this]));
    }

    /**
     * Reset form.
     *
     * @param array|null $data
     */
    public function reset(): void
    {
        $this->traitReset();

        // Reset and initialize the form
        $this->blueprint = null;
        $this->setAllData($this->header_data);
        $this->values = new Data();

        // Fire event
        $grav = Grav::instance();
        $grav->fireEvent('onFormInitialized', new Event(['form' => $this]));
    }

    public function get($name, $default = null, $separator = null)
    {
        switch (strtolower($name)) {
            case 'id':
            case 'uniqueid':
            case 'name':
            case 'noncename':
            case 'nonceaction':
            case 'action':
            case 'data':
            case 'files':
            case 'value':
            case 'errors';
            case 'fields':
            case 'blueprint':
            case 'page':
                $method = 'get' . $name;
                return $this->{$method}();
        }

        return $this->traitGet($name, $default, $separator);
    }

    public function set($name, $default, $separator = null)
    {
        switch (strtolower($name)) {
            case 'id':
            case 'uniqueid':
                $method = 'set' . $name;
                return $this->{$method}();
        }

        return $this->traitSet($name, $default, $separator);
    }

    /**
     * Get the nonce value for a form
     *
     * @return string
     */
    public function getNonce(): string
    {
        return Utils::getNonce($this->getNonceAction());
    }

    /**
     * @inheritdoc
     */
    public function getNonceName(): string
    {
        return $this->items['nonce']['name'];
    }

    /**
     * @inheritdoc
     */
    public function getNonceAction(): string
    {
        return $this->items['nonce']['action'];
    }

    /**
     * @inheritdoc
     */
    public function getValue(string $name)
    {
        return $this->values->get($name);
    }

    /**
     * @return Data
     */
    public function getValues(): Data
    {
        return $this->values;
    }

    /**
     * @inheritdoc
     */
    public function getFields(): array
    {
        return $this->getBlueprint()->fields();
    }

    /**
     * Return page object for the form.
     *
     * @return Page
     */
    public function getPage(): Page
    {
        return Grav::instance()['pages']->dispatch($this->page);
    }

    /**
     * @inheritdoc
     */
    public function getBlueprint(): Blueprint
    {
        if (null === $this->blueprint) {
            // Fix naming for fields (supports nested fields now!)
            if (isset($this->items['fields'])) {
                $this->items['fields'] = $this->processFields($this->items['fields']);
            }

            $blueprint = new Blueprint($this->name, ['form' => $this->items, 'rules' => $this->rules]);
            $blueprint->load()->init();

            $this->blueprint = $blueprint;
        }

        return $this->blueprint;
    }

    /**
     * Allow overriding of fields.
     *
     * @param array $fields
     */
    public function setFields(array $fields = [])
    {
        $this->items['fields'] = $fields;
        unset($this->items['field']);

        // Reset blueprint.
        $this->blueprint = null;

        // Update data to contain the new blueprints.
        $this->setAllData($this->data->toArray());
    }

    /**
     * Get value of given variable (or all values).
     * First look in the $data array, fallback to the $values array
     *
     * @param string $name
     * @param bool $fallback
     * @return mixed
     */
    public function value($name = null, $fallback = false)
    {
        if (!$name) {
            return $this->data;
        }

        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        if ($fallback) {
            return $this->values[$name];
        }

        return null;
    }

    /**
     * Set value of given variable in the values array
     *
     * @param string $name
     * @param mixed $value
     */
    public function setValue($name = null, $value = '')
    {
        if (!$name) {
            return;
        }

        $this->values->set($name, $value);
    }

    /**
     * Set value of given variable in the data array
     *
     * @param string $name
     * @param string $value
     *
     * @return bool
     */
    public function setData($name = null, $value = '')
    {
        if (!$name) {
            return false;
        }

        $this->data->set($name, $value);

        return true;
    }

    public function setAllData($array): void
    {
        $callable = function () {
            return $this->getBlueprint();
        };

        $this->data = new Data($array, $callable);
    }

    /**
     * Handles ajax upload for files.
     * Stores in a flash object the temporary file and deals with potential file errors.
     *
     * @return mixed True if the action was performed.
     */
    public function uploadFiles()
    {
        $grav = Grav::instance();

        /** @var Language $language */
        $language = $grav['language'];
        /** @var Config $config */
        $config = $grav['config'];
        /** @var Uri $uri */
        $uri = $grav['uri'];

        $url = $uri->url;
        $post = $uri->post();

        $name = $post['name'] ?? null;
        $task = $post['task'] ?? null;
        $this->name = $this->items['name'] = $post['__form-name__'] ?? $this->name;
        $this->uniqueid = $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->name;

        $settings = $this->getBlueprint()->schema()->getProperty($name);
        $settings = (object) array_merge(
            ['destination' => $config->get('plugins.form.files.destination', 'self@'),
                'avoid_overwriting' => $config->get('plugins.form.files.avoid_overwriting', false),
                'random_name' => $config->get('plugins.form.files.random_name', false),
                'accept' => $config->get('plugins.form.files.accept', ['image/*']),
                'limit' => $config->get('plugins.form.files.limit', 10),
                'filesize' => static::getMaxFilesize(),
            ],
            (array) $settings,
            ['name' => $name]
        );
        // Allow plugins to adapt settings for a given post name
        // Useful if schema retrieval is not an option, e.g. dynamically created forms
        $grav->fireEvent('onFormUploadSettings', new Event(['settings' => &$settings, 'post' => $post]));

        $upload = json_decode(json_encode($this->normalizeFiles($_FILES['data'], $settings->name)), true);
        $filename = $post['filename'] ?? $upload['file']['name'];
        $field = $upload['field'];

        // Handle errors and breaks without proceeding further
        if ($upload['file']['error'] !== UPLOAD_ERR_OK) {
            // json_response
            return [
                'status' => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_UPLOAD', null, true), $filename, $this->upload_errors[$upload['file']['error']])
            ];
        }

        // Handle bad filenames.
        if (!Utils::checkFilename($filename)) {
            return [
                'status'  => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_UPLOAD', null),
                    $filename, 'Bad filename')
            ];
        }

        if (!isset($settings->destination)) {
            return [
                'status'  => 'error',
                'message' => $language->translate('PLUGIN_FORM.DESTINATION_NOT_SPECIFIED', null)
            ];
        }

        // Remove the error object to avoid storing it
        unset($upload['file']['error']);


        // Handle Accepted file types
        // Accept can only be mime types (image/png | image/*) or file extensions (.pdf|.jpg)
        $accepted = false;
        $errors = [];

        // Do not trust mimetype sent by the browser
        $mime = Utils::getMimeByFilename($filename);

        foreach ((array)$settings->accept as $type) {
            // Force acceptance of any file when star notation
            if ($type === '*') {
                $accepted = true;
                break;
            }

            $isMime = strstr($type, '/');
            $find   = str_replace(['.', '*'], ['\.', '.*'], $type);

            if ($isMime) {
                $match = preg_match('#' . $find . '$#', $mime);
                if (!$match) {
                    $errors[] = sprintf($language->translate('PLUGIN_FORM.INVALID_MIME_TYPE', null, true), $mime, $filename);
                } else {
                    $accepted = true;
                    break;
                }
            } else {
                $match = preg_match('#' . $find . '$#', $filename);
                if (!$match) {
                    $errors[] = sprintf($language->translate('PLUGIN_FORM.INVALID_FILE_EXTENSION', null, true), $filename);
                } else {
                    $accepted = true;
                    break;
                }
            }
        }

        if (!$accepted) {
            // json_response
            return [
                'status' => 'error',
                'message' => implode('<br/>', $errors)
            ];
        }


        // Handle file size limits
        $settings->filesize *= self::BYTES_TO_MB; // 1024 * 1024 [MB in Bytes]
        if ($settings->filesize > 0 && $upload['file']['size'] > $settings->filesize) {
            // json_response
            return [
                'status'  => 'error',
                'message' => $language->translate('PLUGIN_FORM.EXCEEDED_GRAV_FILESIZE_LIMIT')
            ];
        }

        // Generate random name if required
        if ($settings->random_name) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = Utils::generateRandomString(15) . '.' . $extension;
        }

        // Look up for destination
        $destination = $this->getPagePathFromToken(Folder::getRelativePath(rtrim($settings->destination, '/')));

        // Handle conflicting name if needed
        if ($settings->avoid_overwriting) {
            if (file_exists($destination . '/' . $filename)) {
                $filename = date('YmdHis') . '-' . $filename;
            }
        }

        // Prepare object for later save
        $path = $destination . '/' . $filename;
        $upload['file']['name'] = $filename;
        $upload['file']['path'] = $path;

        // We need to store the file into flash object or it will not be available upon save later on.
        $flash = $this->getFlash();
        $flash->setUrl($url)->setUser($grav['user']);

        if ($task === 'cropupload') {
            $crop = $post['crop'];
            if (\is_string($crop)) {
                $crop = json_decode($crop, true);
            }
            $success = $flash->cropFile($field, $filename, $upload, $crop);
        } else {
            $success = $flash->uploadFile($field, $filename, $upload);
        }

        if (!$success) {
            // json_response
            return [
                'status' => 'error',
                'message' => sprintf($language->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '', $flash->getTmpDir())
            ];
        }

        $flash->save();

        // json_response
        $json_response = [
            'status' => 'success',
            'session' => \json_encode([
                'sessionField' => base64_encode($url),
                'path' => $path,
                'field' => $settings->name,
                'uniqueid' => $this->uniqueid
            ])
        ];

        // Return JSON
        header('Content-Type: application/json');
        echo json_encode($json_response);
        exit;
    }

    /**
     * Removes a file from the flash object session, before it gets saved
     *
     * @return bool True if the action was performed.
     */
    public function filesSessionRemove()
    {
        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri  = $grav['uri'];
        $post = $uri->post();
        $field = $post['name'] ?? null;
        $filename = $post['filename'] ?? null;

        if (!isset($field, $filename)) {
            return false;
        }

        $this->name = $this->items['name'] = $post['__form-name__'] ?? $this->name;
        $this->uniqueid = $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->name;

        // Remove image from flash object
        $flash = $this->getFlash();
        $flash->removeFile($filename, $field);
        $flash->save();

        // json_response
        $json_response = ['status' => 'success'];

        // Return JSON
        header('Content-Type: application/json');
        echo json_encode($json_response);
        exit;
    }

    public function storeState()
    {
        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri = $grav['uri'];

        // Get POST data and decode JSON fields into arrays
        $post = $uri->post();
        $post['data'] = $this->decodeData($post['data'] ?? []);

        $this->name = $this->items['name'] = $post['__form-name__'] ?? $this->name;
        $this->uniqueid = $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->name;

        $this->status = 'error';
        if ($post) {
            $this->values = new Data((array)$post);
            if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                return;
            }

            // Store updated data into flash.
            $flash = $this->getFlash();
            $this->setAllData($flash->getData());

            $this->data->merge($this->values->get('data') ?? []);

            $flash->setData($this->data->toArray());
            $flash->save();

            $this->status = 'success';
        }

        // json_response
        $json_response = ['status' => $this->status];

        // Return JSON
        header('Content-Type: application/json');
        echo json_encode($json_response);
        exit;
    }

    /**
     * Handle form processing on POST action.
     */
    public function post()
    {
        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri = $grav['uri'];

        // Get POST data and decode JSON fields into arrays
        $post = $uri->post();
        $post['data'] = $this->decodeData($post['data'] ?? []);

        $this->name = $this->items['name'] = $post['__form-name__'] ?? $this->name;
        $this->uniqueid = $this->items['uniqueid'] = $post['__unique_form_id__'] ?? $this->name;

        if ($post) {
            $this->values = new Data((array)$post);
            $data = $this->values->get('data');

            // Add post data to form dataset
            if (!$data) {
                $data = $this->values->toArray();
            }

            if (!$this->values->get('form-nonce') || !Utils::verifyNonce($this->values->get('form-nonce'), 'form')) {
                $this->status = 'error';
                $event = new Event(['form' => $this,
                    'message' => $grav['language']->translate('PLUGIN_FORM.NONCE_NOT_VALIDATED')
                ]);
                $grav->fireEvent('onFormValidationError', $event);

                return;
            }

            $i = 0;
            foreach ($this->items['fields'] as $key => $field) {
                $name = $field['name'] ?? $key;
                if (!isset($field['name'])) {
                    if (isset($data[$i])) { //Handle input@ false fields
                        $data[$name] = $data[$i];
                        unset($data[$i]);
                    }
                }
                if ($field['type'] === 'checkbox' || $field['type'] === 'switch') {
                    $data[$name] = isset($data[$name]) ? true : false;
                }
                $i++;
            }

            $this->data->merge($data);
        }

        // Validate and filter data
        try {
            $grav->fireEvent('onFormPrepareValidation', new Event(['form' => $this]));

            $this->data->validate();
            $this->data->filter();

            $grav->fireEvent('onFormValidationProcessed', new Event(['form' => $this]));
        } catch (ValidationException $e) {
            $this->status = 'error';
            $event = new Event(['form' => $this, 'message' => $e->getMessage(), 'messages' => $e->getMessages()]);
            $grav->fireEvent('onFormValidationError', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        } catch (\RuntimeException $e) {
            $this->status = 'error';
            $event = new Event(['form' => $this, 'message' => $e->getMessage(), 'messages' => []]);
            $grav->fireEvent('onFormValidationError', $event);
            if ($event->isPropagationStopped()) {
                return;
            }
        }

        $this->legacyUploads();

        $redirect = $redirect_code = null;
        $process = $this->items['process'] ?? [];
        if (\is_array($process)) {
            foreach ($process as $action => $data) {
                if (is_numeric($action)) {
                    $action = \key($data);
                    $data = $data[$action];
                }

                $event = new Event(['form' => $this, 'action' => $action, 'params' => $data]);
                $grav->fireEvent('onFormProcessed', $event);

                if ($event['redirect']) {
                    $redirect = $event['redirect'];
                    $redirect_code = $event['redirect_code'];
                }
                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        }

        $this->copyFiles();

        if ($redirect) {
            $grav->redirect($redirect, $redirect_code);
        }
    }

    /**
     * @return string
     * @deprecated 3.0 Use $this->getName() instead
     */
    public function name(): string
    {
        return $this->getName();
    }

    /**
     * @return array
     * @deprecated 3.0 Use $this->getFields() instead
     */
    public function fields(): array
    {
        return $this->getFields();
    }

    /**
     * @return Page
     * @deprecated 3.0 Use $this->getPage() instead
     */
    public function page(): Page
    {
        return $this->getPage();
    }

    /**
     * Store form uploads to the final location.
     */
    public function copyFiles()
    {
        // Get flash object in order to save the files.
        $flash = $this->getFlash();
        $fields = $flash->getFilesByFields();

        foreach ($fields as $key => $uploads) {
            /** @var FormFlashFile $upload */
            foreach ($uploads as $upload) {
                if (null === $upload || $upload->isMoved()) {
                    continue;
                }

                $destination = $upload->getDestination();
                try {
                    $upload->moveTo($destination);
                } catch (\RuntimeException $e) {
                    $grav = Grav::instance();
                    throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $upload->getClientFilename() . '"', $destination));
                }
            }
        }

        $flash->delete();
    }

    public function getPagePathFromToken($path)
    {
        return Utils::getPagePathFromToken($path, $this->page());
    }

    public function responseCode($code = null)
    {
        if ($code) {
            $this->response_code = $code;
        }
        return $this->response_code;
    }

    public function doSerialize()
    {
        return $this->doTraitSerialize() + [
                'items' => $this->items,
                'message' => $this->message,
                'status' => $this->status,
                'header_data' => $this->header_data,
                'rules' => $this->rules,
                'values' => $this->values->toArray(),
                'page' => $this->page
            ];
    }

    public function doUnserialize(array $data)
    {
        $this->items = $data['items'];
        $this->message = $data['message'];
        $this->status = $data['status'];
        $this->header_data = $data['header_data'];
        $this->rules = $data['rules'];
        $this->values = new Data($data['values']);
        $this->page = $data['page'];

        // Backwards compatibility.
        $defaults = [
            'name' => $this->items['name'],
            'id' => $this->items['id'],
            'uniqueid' => $this->items['uniqueid'],
            'data' => []
        ];

        $this->doTraitUnserialize($data + $defaults);
    }

    /**
     * Get the configured max file size in bytes
     *
     * @param bool $mbytes return size in MB
     * @return int
     */
    public static function getMaxFilesize($mbytes = false)
    {
        $config = Grav::instance()['config'];

        $filesize_mb = (int)($config->get('plugins.form.files.filesize', 0) * static::BYTES_TO_MB);
        $system_filesize = $config->get('system.media.upload_limit', 0);
        if ($filesize_mb > $system_filesize || $filesize_mb === 0) {
            $filesize_mb = $system_filesize;
        }

        if ($mbytes) {
            return $filesize_mb;
        }

        return $filesize_mb  / static::BYTES_TO_MB;
    }

    protected function doSubmit(array $data, array $files)
    {
        return;
    }

    protected function processFields($fields)
    {
        $types = Grav::instance()['plugins']->formFieldTypes;

        $return = [];
        foreach ($fields as $key => $value) {
            // Default to text if not set
            if (!isset($value['type'])) {
                $value['type'] = 'text';
            }

            // Manually merging the field types
            if ($types !== null && array_key_exists($value['type'], $types)) {
                $value += $types[$value['type']];
            }

            // Fix numeric indexes
            if (is_numeric($key) && isset($value['name'])) {
                $key = $value['name'];
            }

            // Recursively process children
            if (isset($value['fields']) && \is_array($value['fields'])) {
                $value['fields'] = $this->processFields($value['fields']);
            }

            $return[$key] = $value;
        }

        return $return;
    }

    protected function legacyUploads()
    {
        // Get flash object in order to save the files.
        $flash = $this->getFlash();
        $queue = $verify = $flash->getLegacyFiles();

        if (!$queue) {
            return;
        }

        $grav = Grav::instance();

        /** @var Uri $uri */
        $uri = $grav['uri'];

        // Get POST data and decode JSON fields into arrays
        $post = $uri->post();
        $post['data'] = $this->decodeData($post['data'] ?? []);

        // Allow plugins to implement additional / alternative logic
        $grav->fireEvent('onFormStoreUploads', new Event(['form' => $this, 'queue' => &$queue, 'post' => $post]));

        $modified = $queue !== $verify;

        if (!$modified) {
            // Fill file fields just like before.
            foreach ($queue as $key => $files) {
                foreach ($files as $destination => $file) {
                    unset($files[$destination]['tmp_name']);
                }

                $this->data->merge([$key => $files]);
            }
        } else {
            user_error('Event onFormStoreUploads is deprecated.', E_USER_DEPRECATED);

            if (\is_array($queue)) {
                foreach ($queue as $key => $files) {
                    foreach ($files as $destination => $file) {
                        if (!rename($file['tmp_name'], $destination)) {
                            $grav = Grav::instance();
                            throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $file['tmp_name'] . '"', $destination));
                        }

                        if (file_exists($file['tmp_name'] . '.yaml')) {
                            unlink($file['tmp_name'] . '.yaml');
                        }

                        unset($files[$destination]['tmp_name']);
                    }

                    $this->data->merge([$key => $files]);
                }
            }

            $flash->delete();
        }
    }

    /**
     * Decode data
     *
     * @param array $data
     * @return array
     */
    protected function decodeData($data)
    {
        if (!\is_array($data)) {
            return [];
        }

        // Decode JSON encoded fields and merge them to data.
        if (isset($data['_json'])) {
            $data = array_replace_recursive($data, $this->jsonDecode($data['_json']));
            unset($data['_json']);
        }

        $data = $this->cleanDataKeys($data);

        return $data;
    }

    /**
     * Decode [] in the data keys
     *
     * @param array $source
     * @return array
     */
    protected function cleanDataKeys($source = [])
    {
        $out = [];

        if (\is_array($source)) {
            foreach ($source as $key => $value) {
                $key = str_replace(['%5B', '%5D'], ['[', ']'], $key);
                if (\is_array($value)) {
                    $out[$key] = $this->cleanDataKeys($value);
                } else {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Internal method to normalize the $_FILES array
     *
     * @param array  $data $_FILES starting point data
     * @param string $key
     * @return object a new Object with a normalized list of files
     */
    protected function normalizeFiles($data, $key = '')
    {
        $files = new \stdClass();
        $files->field = $key;
        $files->file = new \stdClass();

        foreach ($data as $fieldName => $fieldValue) {
            // Since Files Upload are always happening via Ajax
            // we are not interested in handling `multiple="true"`
            // because they are always handled one at a time.
            // For this reason we normalize the value to string,
            // in case it is arriving as an array.
            $value = (array) Utils::getDotNotation($fieldValue, $key);
            $files->file->{$fieldName} = array_shift($value);
        }

        return $files;
    }
}
