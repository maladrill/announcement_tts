<?php

namespace FreePBX\modules\Announcementtts\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;

class Announcementtts extends Base {
	protected $module = 'announcementstts';
	protected $description = 'Plays back one of the system recordings (optionally allowing the user to skip it) and then goes to another destinations';

	public function mutationCallback() {
		if($this->checkAllWriteScope()) {
			return fn() => [
					'addAnnouncementtts' => Relay::mutationWithClientMutationId([
						'name' => 'addAnnouncementtts',
						'description' => 'Add a new announcementtts to the system',
						'inputFields' => [
							'description' => [
								'type' => Type::nonNull(Type::string()),
								'description' => 'The name of this announcementtts'
							],
							'allow_skip' => [
								'type' => Type::boolean(),
								'description' => 'If the caller is allowed to press a key to skip the message'
							],
							'return_ivr' => [
								'type' => Type::boolean(),
								'description' => "If this announcementtts came from an IVR and this is true, the destination will be ignored and instead it will return to the calling IVR. Otherwise, the destination below will be taken. Don't set if not using in this mode. The IVR return location will be to the last IVR in the call chain that was called so be careful to only check when needed. For example, if an IVR directs a call to another destination which eventually calls this announcementtts and this is checked, it will return to that IVR which may not be the expected behavior."
							],
							'noanswer' => [
								'type' => Type::boolean(),
								'description' => 'Set this to true to keep the channel from explicitly being answered. When set, the message will be played and if the channel is not already answered it will be delivered as early media if the channel supports that. When not checked, the channel is answered followed by a 1 second delay. When using an announcementtts from an IVR or other sources that have already answered the channel, that 1 second delay may not be desired'
							],
							'repeat_msg' => [
								'type' => Type::int(),
								'description' => 'Key to press that will allow for the message to be replayed. If you choose this option there will be a short delay inserted after the message. If a longer delay is needed it should be incorporated into the recording'
							],
							'recording_id' => [
								'type' => Type::int()
							],
							'post_dest' => [
								'type' => Type::nonNull(Type::string())
							]
						],
						'outputFields' => [
							'announcementtts' => [
								'type' => $this->typeContainer->get('announcementtts')->getObject(),
								'resolve' => fn($payload) => $payload
							]
						],
						'mutateAndGetPayload' => function ($input) {
							$defaults = [
								'description' => '',
								'recording_id' => null,
								'allow_skip' => false,
								'post_dest' => null,
								'return_ivr' => false,
								'noanswer' => false,
								'repeat_msg' => 0
							];
							foreach($defaults as $key => $value) {
								if(!isset($input[$key])) {
									$input[$key] = $value;
								}
							}

							$id = $this->freepbx->Announcementtts->addAnnouncementtts($input['description'], $input['recording_id'], $input['allow_skip'], $input['post_dest'], $input['return_ivr'], $input['noanswer'], $input['repeat_msg']);
							return $this->freepbx->Announcementtts->getAnnouncementttsByID($id);
						}
					]),
					'updateAnnouncementtts' => Relay::mutationWithClientMutationId([
						'name' => 'updateAnnouncementtts',
						'description' => 'Update an announcementtts on the system',
						'inputFields' => [
							'announcementtts_id' => [
								'type' => Type::nonNull(Type::id()),
								'description' => 'The id of this announcementtts'
							],
							'description' => [
								'type' => Type::nonNull(Type::string()),
								'description' => 'The name of this announcementtts'
							],
							'allow_skip' => [
								'type' => Type::boolean(),
								'description' => 'If the caller is allowed to press a key to skip the message'
							],
							'return_ivr' => [
								'type' => Type::boolean(),
								'description' => "If this announcementtts came from an IVR and this is true, the destination will be ignored and instead it will return to the calling IVR. Otherwise, the destination below will be taken. Don't set if not using in this mode. The IVR return location will be to the last IVR in the call chain that was called so be careful to only check when needed. For example, if an IVR directs a call to another destination which eventually calls this announcementtts and this is checked, it will return to that IVR which may not be the expected behavior."
							],
							'noanswer' => [
								'type' => Type::boolean(),
								'description' => 'Set this to true to keep the channel from explicitly being answered. When set, the message will be played and if the channel is not already answered it will be delivered as early media if the channel supports that. When not checked, the channel is answered followed by a 1 second delay. When using an announcementtts from an IVR or other sources that have already answered the channel, that 1 second delay may not be desired'
							],
							'repeat_msg' => [
								'type' => Type::int(),
								'description' => 'Key to press that will allow for the message to be replayed. If you choose this option there will be a short delay inserted after the message. If a longer delay is needed it should be incorporated into the recording'
							],
							'recording_id' => [
								'type' => Type::int()
							],
							'post_dest' => [
								'type' => Type::nonNull(Type::string())
							]
						],
						'outputFields' => [
							'announcementtts' => [
								'type' => $this->typeContainer->get('announcementtts')->getObject(),
								'resolve' => fn($payload) => $payload
							]
						],
						'mutateAndGetPayload' => function ($input) {
							$defaults = [
								'description' => '',
								'recording_id' => null,
								'allow_skip' => false,
								'post_dest' => false,
								'return_ivr' => false,
								'noanswer' => 0,
								'repeat_msg' => null
							];
							foreach($defaults as $key => $value) {
								if(!isset($input[$key])) {
									$input[$key] = $value;
								}
							}

							$id = $this->freepbx->Announcementtts->editAnnouncementtts($input['announcementtts_id'],$input['description'], $input['recording_id'], $input['allow_skip'], $input['post_dest'], $input['return_ivr'], $input['noanswer'], $input['repeat_msg']);
							return $this->freepbx->Announcementtts->getAnnouncementttsByID($id);
						}
					]),
					'removeAnnouncementtts' => Relay::mutationWithClientMutationId([
						'name' => 'removeAnnouncementtts',
						'description' => 'Remove an announcementtts from the system',
						'inputFields' => [
							'id' => [
								'type' => Type::nonNull(Type::int())
							]
						],
						'outputFields' => [
							'deletedId' => [
								'type' => Type::nonNull(Type::id()),
								'resolve' => fn($payload) => $payload['id']
							]
						],
						'mutateAndGetPayload' => function ($input) {
							$this->freepbx->Announcementtts->deleteAnnouncementtts($input['id']);
							return ['id' => $input['id']];
						}
					])
				];
		}
	}

	public function queryCallback() {
		if($this->checkAllReadScope()) {
			return fn() => [
					'allAnnouncementstts' => [
						'type' => $this->typeContainer->get('announcementtts')->getConnectionType(),
						'description' => $this->description,
						'args' => Relay::connectionArgs(),
						'resolve' => fn($root, $args) => Relay::connectionFromArray($this->freepbx->Announcementtts->getAnnouncementstts(), $args),
					],
					'announcementtts' => [
						'type' => $this->typeContainer->get('announcementtts')->getObject(),
						'description' => $this->description,
						'args' => [
							'id' => [
								'type' => Type::id(),
								'description' => 'Announcementtts ID',
							]
						],
						'resolve' => fn($root, $args) => $this->freepbx->Announcementtts->getAnnouncementttsByID($args['id'])
					]
				];
		}
	}

	public function postInitializeTypes() {
		$destinations = $this->typeContainer->get('destination');
		$destinations->addTypeCallback(fn() => [
				$this->typeContainer->get('announcementtts')->getObject()
			]);

		$destinations->addResolveTypeCallback(function($value, $context, $info) {
			if (is_array($value) && $value['graphqlType'] == 'announcementtts') {
				return $this->typeContainer->get('announcementtts')->getObject();
			}
		});

		$destinations->addResolveValueCallback(function($value) {
			if (str_starts_with(trim($value), 'app-announcementtts-')) {
				$exten = explode(',',$value);
				$exten = substr($exten[0],17);
				$out = $this->freepbx->Announcementtts->getAnnouncementttsByID($exten);
				if(!empty($out)) {
					return array_merge($out,['graphqlType' => 'announcementtts']);
				}
			}
		});
	}

	public function initializeTypes() {
		$user = $this->typeContainer->create('announcementtts');
		$user->setDescription('Plays back one of the system recordings (optionally allowing the user to skip it) and then goes to another destination');

		$user->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);

		$user->setGetNodeCallback(function($id) {
			$item = $this->freepbx->Announcementtts->getAnnouncementttsByID($id);
			return !empty($item) ? $item : null;
		});

		$user->addFieldCallback(fn() => [
				'id' => Relay::globalIdField('announcementtts', fn($row) => $row['announcementtts_id']),
				'announcementts_id' => [
					'type' => Type::int(),
					'description' => 'The announcementtts id'
				],
				'description' => [
					'type' => Type::string(),
					'description' => 'The name of this announcementtts'
				],
				'allow_skip' => [
					'type' => Type::boolean(),
					'description' => 'If the caller is allowed to press a key to skip the message'
				],
				'return_ivr' => [
					'type' => Type::boolean(),
					'description' => "If this announcementtts came from an IVR and this is true, the destination will be ignored and instead it will return to the calling IVR. Otherwise, the destination below will be taken. Don't set if not using in this mode. The IVR return location will be to the last IVR in the call chain that was called so be careful to only check when needed. For example, if an IVR directs a call to another destination which eventually calls this announcementtts and this is checked, it will return to that IVR which may not be the expected behavior."
				],
				'noanswer' => [
					'type' => Type::boolean(),
					'description' => 'Set this to true to keep the channel from explicitly being answered. When set, the message will be played and if the channel is not already answered it will be delivered as early media if the channel supports that. When not checked, the channel is answered followed by a 1 second delay. When using an announcementtts from an IVR or other sources that have already answered the channel, that 1 second delay may not be desired'
				],
				'repeat_msg' => [
					'type' => Type::int(),
					'description' => 'Key to press that will allow for the message to be replayed. If you choose this option there will be a short delay inserted after the message. If a longer delay is needed it should be incorporated into the recording'
				],
				'destinationConnection' => [
					'type' => $this->typeContainer->get('destination')->getObject(),
					'description' => 'Where to send the caller after the announcementtts is played',
					'resolve' => fn($row) => $this->typeContainer->get('destination')->resolveValue($row['post_dest'])
				]
			]);

		$user->setConnectionResolveNode(fn($edge) => $edge['node']);

		$user->setConnectionFields(fn() => [
				'totalCount' => [
					'type' => Type::int(),
					'resolve' => fn($value) => is_countable($this->freepbx->Announcementtts->getAnnouncementstts()) ? count($this->freepbx->Announcementtts->getAnnouncementstts()) : 0
				],
				'announcementstts' => [
					'type' => Type::listOf($this->typeContainer->get('announcementtts')->getObject()),
					'description' => $this->description,
					'resolve' => function($root, $args) {
						$data = array_map(fn($row) => $row['node'],$root['edges']);
						return $data;
					}
				]
			]);
	}
}
