<?php namespace App\Controllers;

use App\Models\DocumentModel;
use App\Models\AttachmentModel;
use App\Models\TagModel;
use App\Models\StatusModel;
use App\Models\PermissionModel;

class DocumentsController extends BaseController
{
    protected $permissionSection = "documents";

    public function all_v1()
	{
        $db = db_connect();
        $documentModel = new DocumentModel();
        $documentModel->user = $this->request->user;
        $response = new \stdClass();
        $response->documents = $documentModel
            ->select("documents.*")
            ->join("permissions", "permissions.resource = documents.id AND permissions.user = ".$db->escape($this->request->user->id), 'left')
            ->groupStart()
                ->where("public", 1)
                ->orGroupStart()
                    ->where("public", 0)
                    ->where("owner", $this->request->user->id)
                ->groupEnd()
                ->orWhere("permissions.permission", !NULL)
            ->groupEnd()
            ->findAll();

        // load the global tags
        $tagModel = new TagModel();
        $response->tags = $tagModel->where("project", NULL)->findAll();
        foreach ($response->tags as &$tag) {
            unset($tag->project);
        }

        // load the global statuses
        $statusModel = new StatusModel();
        $response->statuses = $statusModel->where("project", NULL)->findAll();
        foreach ($response->statuses as &$status) {
            unset($status->project);
        }

        return $this->reply($response);
    }

	public function one_v1($documentId)
	{
        if (!isset($documentId)) {
            return $this->reply(null, 500, "ERR-DOCUMENTS-GET");
        }

        $document = $this->getDocument($documentId);
        return $this->reply($document);
    }

    public function add_v1()
    {
        $user = $this->request->user;
        $documentModel = new DocumentModel();
        $documentModel->user = $user;

        $data = $this->request->getJSON();
        $documentModel->formatData($data);

        // inserting the new document
        try {
            if ($documentModel->insert($data) === false) {
                return $this->reply($documentModel->errors(), 500, "ERR-DOCUMENTS-CREATE");
            }
        } catch (\Exception $e) {
            return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-CREATE");
        }

        // inserting the default document permission 
        $permissionModel = new PermissionModel();
        $permission = [
            "resource" => $data->id,
            "permission" => "FULL"
        ];
        try {
            if ($permissionModel->insert($permission) === false) {
                return $this->reply($permissionModel->errors(), 500, "ERR-DOCUMENTS-CREATE");
            }
        } catch (\Exception $e) {
            return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-CREATE");
        }

        // inserting activity
        $this->addActivity(
            $data->id,
            $data->parent,
            $data->id,
            $this::ACTION_CREATE,
            $this::SECTION_DOCUMENTS
        );
        
        $document = $this->getDocument($data->id);

        return $this->reply($document);
    }

    public function update_v1($documentId)
    {
        // check for unknown record types
        if (!isset($documentId)) {
            return $this->reply("Document `id` missing or not valid", 500, "ERR-DOCUMENTS-UPDATE");
        }

        $documentModel = new DocumentModel();
        $documentModel->user = $this->request->user;
        
        // checking if the requested document exists
        $document = $this->getDocument($documentId);
        
        if (!$document) {
            return $this->reply("Document not found", 404, "ERR-DOCUMENTS-UPDATE");
        }

        // checking user permissions to update this document
        if (!$this->can($document->permission, "update")) {
            return $this->reply("You don't have permissions to update this document", 403, "ERR-DOCUMENTS-UPDATE");
        }

        $data = $this->request->getJSON();
        $data->id = $documentId;
        $documentModel->formatData($data);

        $this->lock($data->id);

        // reverting back to the old parent in case it's missing
        if (!isset($data->parent)) {
            $data->parent = $document->parent;
        }

        // adding updated date in case it's missing
        if (!isset($data->updated)) {
            $data->updated = date("Y-m-d H:i:s");
        }

        unset($data->type); // just in case someone tries to change types
        unset($data->created);
        unset($data->owner); // we don't want to change the owner

        // checking if someone without privilages changes the visibility
        if ($data->public != $document->public && $data->owner != $document->owner) {
            $data->public = $document->public;
        }

        // updating the document
        try {
            if ($documentModel->update($document->id, $data) === false) {
                return $this->reply($documentModel->errors(), 500, "ERR-DOCUMENTS-UPDATE");
            }
        } catch (\Exception $e) {
            return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-UPDATE");
        }

        // in case it's a file document
        // we also need to rename the file
        if ($document->type === "file") {
            $attachmentModel = new AttachmentModel();
            try {
                if ($attachmentModel->wherein("resource", [$document->id])->set("content", $data->text)->update() === false) {
                    return $this->reply($attachmentModel->errors(), 500, "ERR-DOCUMENTS-UPDATE");
                }
            } catch (\Exception $e) {
                return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-UPDATE");
            }
            
        }

        // inserting activity
        $this->addActivity(
            $data->id,
            $data->parent,
            $data->id,
            $this::ACTION_UPDATE,
            $this::SECTION_DOCUMENTS
        );
        return $this->reply(true);
    }

    public function order_v1()
    {
        $orderData = $this->request->getJSON();
        $db = db_connect();
        
        // get all documents
        $documentModel = new DocumentModel();
        $documents = $documentModel->orderBy("position", "asc")->findAll();

        $documentsNeedUpdate = array();
        foreach ($orderData as $parentId => $documentsOrder) {
            foreach ($documentsOrder as $documentOrder => $documentId) {
                $document = new \stdClass();
                $document->id = $documentId;
                $document->order = $documentOrder + 1;
                $document->parent = $parentId;
                $documentsNeedUpdate[] = $document;
            }
        }


        // update documents order
        if (count($documentsNeedUpdate)) {
            $documentsOrderQuery = array(
                "INSERT INTO ".$db->prefixTable("documents")." (`id`, `parent`, `position`) VALUES"
            );

            foreach ($documentsNeedUpdate as $i => $document) {
                $value = "(". $db->escape($document->id) .", ". $db->escape($document->parent) .", ". $db->escape($document->order) .")";
                if ($i < count($documentsNeedUpdate) - 1) {
                    $value .= ",";
                }
                $documentsOrderQuery[] = $value;
            }

            $documentsOrderQuery[] = "ON DUPLICATE KEY UPDATE id=VALUES(id), `parent`=VALUES(`parent`), `position`=VALUES(`position`);";
            $documentsQuery = implode(" ", $documentsOrderQuery);

            if (!$db->query($documentsQuery)) {
                return $this->reply("Unable to update documents order", 500, "ERR-DOCUMENTS-REORDER");
            }
        }

        return $this->reply(true);
    }

    public function delete_v1($documentId)
    {        
        $document = $this->getDocument($documentId);

        if (!isset($document->id)) {
            return $this->reply("Document not found", 404, "ERR-DOCUMENTS-DELETE");
        }

        // documents that require other to be deleted
        $documentsToCleanUp = array();
        if ($document->type !== "folder") {
            $documentsToCleanUp[] = $document;
        }

        $documentModel = new DocumentModel();
        try {
            if ($documentModel->delete([$document->id]) === false) {
                return $this->reply($documentModel->errors(), 500, "ERR-DOCUMENTS-DELETE1");
            }    
        } catch (\Exception $e) {
            return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-DELETE2");
        }

        helper("documents");

        // in case the user deleted a folder
        // then also delete all sub documents
        if ($document->type === "folder") {
            $documents = $documentModel->findAll();
            $documentsToDelete = documents_get_tree($documents, $document->id);
            $idsToDelete = array();

            foreach ($documentsToDelete as $documentToDelete) {
                $idsToDelete[] = $documentToDelete->id;
                if ($documentToDelete->type !== "folder") {
                    $documentsToCleanUp[] = $documentToDelete;
                }
            }

            if (count($idsToDelete)) {
                try {
                    if ($documentModel->delete($idsToDelete) === false) {
                        return $this->reply($documentModel->errors(), 500, "ERR-DOCUMENTS-DELETE3");
                    }    
                } catch (\Exception $e) {
                    return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-DELETE4");
                }
            }
        }

        // cleaning up
        if (count($documentsToCleanUp)) {
            if (!documents_clean_up($documentsToCleanUp)) {
                return $this->reply("Unable to delete document", 500, "ERR-DOCUMENTS-DELETE5");
            }
        }

        $this->addActivity(
            $document->id,
            $document->parent,
            $document->id,
            $this::ACTION_DELETE,
            $this::SECTION_DOCUMENTS
        );
        return $this->reply(true);
    }

    public function update_options_v1($documentId)
    {
        $options = $this->request->getJSON();
        $document = $this->getDocument($documentId);

        if (!isset($document->id)) {
            return $this->reply("Document not found", 404, "ERR-DOCUMENTS-OPTIONS");
        }

        $documentModel = new DocumentModel();
        try {
            if ($documentModel->update($document->id, ["options" => json_encode($options)]) === false) {
                return $this->reply($documentModel->errors(), 500, "ERR-DOCUMENTS-OPTIONS");
            }    
        } catch (\Exception $e) {
            return $this->reply($e->getMessage(), 500, "ERR-DOCUMENTS-OPTIONS");
        }

        return $this->reply(true);
    }

    public function attachments_v1($documentId)
    {        
        $document = $this->getDocument($documentId);

        if (!isset($document->id)) {
            return $this->reply("Document not found", 404, "ERR-DOCUMENTS-ATTACHMENTS");
        }

        $attachmentModel = new AttachmentModel();
        $attachments = $attachmentModel->where("resource", $documentId)->find();

        return $this->reply($attachments);
    }
}
