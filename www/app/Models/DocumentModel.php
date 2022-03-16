<?php namespace App\Models;

use CodeIgniter\Model;

class DocumentModel extends Model
{
    protected $table      = "documents";
    protected $primaryKey = "id";
    protected $returnType = "object";

    protected $allowedFields = ["id", "text", "parent", "owner", "public", "type", "position", "options"];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField  = "created";
    protected $updatedField  = "updated";
    protected $deletedField  = "deleted";

    protected $afterFind = ["formatDocuments"];
    protected $beforeInsert = ["setDocument"];

    protected $validationRules = [
        "text" => "required|alpha_numeric_punct",
        "parent" => "required|alpha_numeric_punct",
        "owner" => "required|integer",
        "type" => "in_list[folder,project,notepad,people,file]",
        "position" => "required|numeric"
    ];

    protected function formatDocuments(array $data)
    {
        helper("documents");
        $user = $this->user;

        // single document
        if ($data["method"] === "find") {
            $data["data"]->public = boolval($data["data"]->public);

            if ($data["data"]->options) {
                $data["data"]->options = json_decode($data["data"]->options);
            }

            // removing unncessary prop
            unset($data["data"]->deleted);
            unset($data["data"]->position);

            documents_expand_document($data["data"], $user);
        }

        // all documents
        if ($data["method"] === "findAll") {
            $projects = array();
            $people = array();

            foreach ($data["data"] as $key => &$document) {
                $document->public = boolval($document->public);

                if ($document->parent === "0") {
                    $document->parent = 0;
                } else {
                    $document->parent = null;
                }

                if ($document->type === "folder") {
                    $document->droppable = true;
                } else if ($document->type === "project") {
                    $projects[] = $document->id;
                } else if ($document->type === "people") {
                    $people[] = $document->id;
                }

                $document->data = new \stdClass();
                $document->data->type = $document->type;
                unset($document->type);
                $document->data->created = $document->created;
                unset($document->created);
                $document->data->updated = $document->updated;
                unset($document->updated);
                $document->data->public = boolval($document->public);
                unset($document->public);
                $document->data->owner = $document->owner;
                unset($document->owner);
                unset($document->options);
                unset($document->deleted);
            }

            // load the counters used in the sidebar
            documents_load_counters($data["data"]);
            // load the documents permissions
            documents_load_permissions($data["data"], $user);
        }

        return $data;
    }

    protected function setDocument(array $data)
    {
        helper("documents");

        // checking for parent
        if (!isset($data["data"]["parent"])) {
            $data["data"]["parent"] = 0;
        }

        // fixing options
        if (isset($data["data"]["options"])) {
            $data["data"]["options"] = json_encode($data["data"]["options"]);
        } else {
            $data["data"]["options"] = json_encode(documents_get_default_options($data["data"]["type"]));
        }

        // fixing visibility
        if (!isset($data["data"]["public"])) {
            $data["data"]["public"] = 1;
        }

        return $data;
    } 
}