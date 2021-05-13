<?php
namespace App\Controllers;

use CodeIgniter\Events\Events;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 *
 * @package CodeIgniter
 */

use CodeIgniter\Controller;

class BaseController extends Controller
{

	/**
	 * An array of helpers to be loaded automatically upon
	 * class instantiation. These helpers will be available
	 * to all other controllers that extend BaseController.
	 *
	 * @var array
	 */
    protected $helpers = [];
    protected $lockId = null;

    const TYPE_FOLDER = "folder";
    const TYPE_PROJECT = "project";
    const TYPE_NOTEPAD = "notepad";
    const TYPE_PEOPLE = "people";

    const ACTION_CREATE = "CREATE";
    const ACTION_UPDATE = "UPDATE";
    const ACTION_DELETE = "DELETE";
    const ACTION_UNKNOWN = "UNKNOWN";

    const SECTION_DOCUMENTS = "DOCUMENTS";
    const SECTION_FOLDER = "FOLDER";
    const SECTION_DOCUMENT = "DOCUMENT";
    const SECTION_PROJECT = "PROJECT";
    const SECTION_NOTEPAD = "NOTEPAD";
    const SECTION_PEOPLE = "PEOPLE";
    const SECTION_TASK = "TASK";
    const SECTION_STACK = "STACK";
    const SECTION_WATCHER = "WATCHER";
    const SECTION_UNKNOWN = "UNKNOWN";

	/**
	 * Constructor.
	 */
	public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
	{
		// Do Not Edit This Line
		parent::initController($request, $response, $logger);

		//--------------------------------------------------------------------
		// Preload any models, libraries, etc, here.
		//--------------------------------------------------------------------
		// E.g.:
		// $this->session = \Config\Services::session();
	}

    public function reply($data = null, $code = 200, $msg = null, $unlock = true)
    {
        $response = new \stdClass();
        $response->message = $msg;
        $response->code = $code;
        $response->data = $data;

        if ($unlock) {
            $this->unlock();
        }

        return $this->response->setStatusCode($code)->setJSON($response);
    }

    protected function lock($id)
    {
        $user = $this->request->user;

        $lockedBy = cache($id);

        if ($lockedBy) {
            $this->reply($lockedBy, 423, "WRN-RESOURCE-LOCKED", false)->send();
            die();
        }
        $this->lockId = $id;
        cache()->save($id, $user, 60);
    }

    protected function unlock()
    {
        if ($this->lockId) {
            cache()->delete($this->lockId);
        }
    }

    protected function addActivity($parent = "", $item, $action, $section)
    {
        Events::trigger('activity', $parent, $action, $section, $item);
    }
}
