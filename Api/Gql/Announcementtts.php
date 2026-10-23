<?php
namespace FreePBX\modules\Announcementtts\Api\Gql;
use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;

class Announcementtts extends Base {
    protected $module = 'announcementtts';
    protected $description = 'Text-to-speech announcements';

    private function fields(bool $update): array {
        $fields = [];
        if ($update) { $fields['announcementtts_id'] = ['type' => Type::nonNull(Type::id())]; }
        foreach (['description', 'text', 'post_dest'] as $name) { $fields[$name] = ['type' => Type::nonNull(Type::string())]; }
        foreach (['language' => 'pl', 'voice' => 'fable'] as $name => $default) {
            $fields[$name] = ['type' => Type::string(), 'defaultValue' => $default];
        }
        if ($update) { $fields['regenerate'] = ['type' => Type::boolean(), 'defaultValue' => false]; }
        return $fields;
    }
    public function mutationCallback() {
        if (!$this->checkAllWriteScope()) { return null; }
        return function (): array {
            $mutations = [];
            foreach (['addAnnouncementtts' => false, 'updateAnnouncementtts' => true] as $name => $update) {
                $mutations[$name] = Relay::mutationWithClientMutationId([
                    'name' => $name,
                    'inputFields' => $this->fields($update),
                    'outputFields' => ['announcementtts' => [
                        'type' => $this->typeContainer->get('announcementtts')->getObject(), 'resolve' => static fn($payload) => $payload,
                    ]],
                    'mutateAndGetPayload' => function ($input) use ($update) {
                        $module = $this->freepbx->Announcementtts;
                        if ($update) {
                            $id = $module->editAnnouncementtts($input['announcementtts_id'], $input['description'], $input['post_dest'],
                                $input['text'], $input['language'] ?? 'pl', $input['voice'] ?? 'fable', $input['regenerate'] ?? false);
                        } else {
                            $id = $module->addAnnouncementtts($input['description'], $input['post_dest'], $input['text'],
                                $input['language'] ?? 'pl', $input['voice'] ?? 'fable');
                        }
                        return $module->getAnnouncementttsByID($id);
                    },
                ]);
            }
            $mutations['removeAnnouncementtts'] = Relay::mutationWithClientMutationId([
                'name' => 'removeAnnouncementtts', 'inputFields' => ['id' => ['type' => Type::nonNull(Type::int())]],
                'outputFields' => ['deletedId' => ['type' => Type::nonNull(Type::id()), 'resolve' => static fn($p) => $p['id']]],
                'mutateAndGetPayload' => function ($input) {
                    $this->freepbx->Announcementtts->deleteAnnouncementtts($input['id']);
                    return ['id' => $input['id']];
                },
            ]);
            return $mutations;
        };
    }
    public function queryCallback() {
        if (!$this->checkAllReadScope()) { return null; }
        return fn() => [
            'allAnnouncementstts' => [
                'type' => $this->typeContainer->get('announcementtts')->getConnectionType(),
                'args' => Relay::connectionArgs(),
                'resolve' => fn($root, $args) => Relay::connectionFromArray($this->freepbx->Announcementtts->getAnnouncementstts(), $args),
            ],
            'announcementtts' => [
                'type' => $this->typeContainer->get('announcementtts')->getObject(),
                'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
                'resolve' => fn($root, $args) => $this->freepbx->Announcementtts->getAnnouncementttsByID($args['id']) ?: null,
            ],
        ];
    }
    public function postInitializeTypes() {
        $destinations = $this->typeContainer->get('destination');
        $destinations->addTypeCallback(fn() => [$this->typeContainer->get('announcementtts')->getObject()]);
        $destinations->addResolveTypeCallback(function ($value, $context, $info) {
            if (is_array($value) && ($value['graphqlType'] ?? '') === 'announcementtts') { return $this->typeContainer->get('announcementtts')->getObject(); }
            return null;
        });
        $destinations->addResolveValueCallback(function ($value) {
            if ($this->checkAllReadScope() && is_string($value) && preg_match('/^app-announcementtts-([1-9][0-9]*),s,1$/D', $value, $m)) {
                $row = $this->freepbx->Announcementtts->getAnnouncementttsByID($m[1]);
                return $row ? array_merge($row, ['graphqlType' => 'announcementtts']) : null;
            }
            return null;
        });
    }
    public function initializeTypes() {
        $type = $this->typeContainer->create('announcementtts');
        $type->setDescription($this->description);
        $type->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);
        $type->setGetNodeCallback(fn($id) => $this->checkAllReadScope() ? ($this->freepbx->Announcementtts->getAnnouncementttsByID($id) ?: null) : null);
        $type->addFieldCallback(function (): array {
            $fields = [
                'id' => Relay::globalIdField('announcementtts', static fn($row) => $row['announcementtts_id']),
                'announcementtts_id' => ['type' => Type::int()],
                'announcementts_id' => ['type' => Type::int(), 'deprecationReason' => 'Use announcementtts_id.', 'resolve' => static fn($r) => $r['announcementtts_id']],
                'destinationConnection' => [
                    'type' => $this->typeContainer->get('destination')->getObject(),
                    'resolve' => fn($r) => $this->typeContainer->get('destination')->resolveValue($r['post_dest']),
                ],
            ];
            foreach (['description', 'text', 'language', 'voice', 'post_dest'] as $name) { $fields[$name] = ['type' => Type::string()]; }
            return $fields;
        });
        $type->setConnectionResolveNode(static fn($edge) => $edge['node']);
        $type->setConnectionFields(fn() => [
            'totalCount' => ['type' => Type::int(), 'resolve' => fn() => count($this->freepbx->Announcementtts->getAnnouncementstts())],
            'announcementstts' => ['type' => Type::listOf($type->getObject()), 'resolve' => static fn($r) => array_column($r['edges'], 'node')],
        ]);
    }
}
