-- O inventário de dispositivos, capturado do hub de produção (sockets.hitcare.net).
--
-- Cada instrução é idempotente e resolve os ids por chave natural, para este ficheiro nunca
-- fixar um valor de auto-increment: os fornecedores e as empresas casam pelo nome UNIQUE, os
-- modelos pelo uq_models_supplier_internal_model, as licenças pelo
-- uq_licenses_company_license, e os dispositivos pelo whitelist.imei.
--
-- Os dispositivos e as ligações a gateways usam INSERT IGNORE: uma base nova recebe o
-- inventário todo, e uma base já editada à mão fica com as tuas edições. Os modelos fazem
-- upsert, porque o ReferenceCatalogSeeder cria um subconjunto deles primeiro, com o
-- image_path vazio e um commercial_name provisório.
--
-- Deliberadamente NÃO semeados: api_users (hashes de password), device_configurations e
-- device_configuration_changes/operations (estado de sincronização vivo por dispositivo, que
-- punha cada um a parecer ter alterações pendentes que não consegue confirmar),
-- private_radio_map_access_points (aprendidos em execução) e dashboard_notifications.

INSERT IGNORE INTO suppliers (name) VALUES
    ('4P Touch'),
    ('MOKO'),
    ('MONIT'),
    ('Qinglanst'),
    ('Vivistar'),
    ('Voerka'),
    ('Wonlex');

INSERT IGNORE INTO companies (name) VALUES
    ('havicare'),
    ('hitcare');

INSERT INTO licenses (company_id, license_id, name)
SELECT c.id, 1, 'hc.dev' FROM companies c WHERE c.name = 'havicare'
ON DUPLICATE KEY UPDATE name = 'hc.dev';

INSERT INTO licenses (company_id, license_id, name)
SELECT c.id, 22, 'hc.simplificado' FROM companies c WHERE c.name = 'havicare'
ON DUPLICATE KEY UPDATE name = 'hc.simplificado';

INSERT INTO licenses (company_id, license_id, name)
SELECT c.id, 1001, 'gucc.dev' FROM companies c WHERE c.name = 'hitcare'
ON DUPLICATE KEY UPDATE name = 'gucc.dev';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'D41', 'D41', 'watch', '/model-images/9201181e4f07060bd5ded5e48ca8e20a.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'D41', device_type = 'watch',
    image_path = '/model-images/9201181e4f07060bd5ded5e48ca8e20a.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'D44S', 'R05', 'watch', '/model-images/be4e5160e602a993f519011e6c9f796c.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'R05', device_type = 'watch',
    image_path = '/model-images/be4e5160e602a993f519011e6c9f796c.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'D45 Pro', 'D45 Pro', 'watch', '/model-images/4a088f59242d03d7023d5e51d4da8e49.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'D45 Pro', device_type = 'watch',
    image_path = '/model-images/4a088f59242d03d7023d5e51d4da8e49.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'D46', 'R04', 'watch', '/model-images/1347f078cd3c213a48495d8f1a366713.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'R04', device_type = 'watch',
    image_path = '/model-images/1347f078cd3c213a48495d8f1a366713.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'Y6M', 'Y6M', 'watch', '/model-images/9648d481eb3381148ea91c84aba2687c.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'Y6M', device_type = 'watch',
    image_path = '/model-images/9648d481eb3381148ea91c84aba2687c.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'Y6S', 'R03', 'watch', '/model-images/3d48c42e589923177a1ac3ed147758e0.jpg'
FROM suppliers s WHERE s.name = '4P Touch'
ON DUPLICATE KEY UPDATE commercial_name = 'R03', device_type = 'watch',
    image_path = '/model-images/3d48c42e589923177a1ac3ed147758e0.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'MKGW3', 'MOKOSmart MKGW3', 'gateway', '/model-images/45bee5a0028156faa71ff5c6c081b6d7.jpg'
FROM suppliers s WHERE s.name = 'MOKO'
ON DUPLICATE KEY UPDATE commercial_name = 'MOKOSmart MKGW3', device_type = 'gateway',
    image_path = '/model-images/45bee5a0028156faa71ff5c6c081b6d7.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'MKGW4', 'MOKOSmart MKGW4', 'gateway', '/model-images/3bbccf9f4d8e4830480adf834cdfd278.jpg'
FROM suppliers s WHERE s.name = 'MOKO'
ON DUPLICATE KEY UPDATE commercial_name = 'MOKOSmart MKGW4', device_type = 'gateway',
    image_path = '/model-images/3bbccf9f4d8e4830480adf834cdfd278.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'W6R', 'MOKO W6R', 'bracelet', '/model-images/78888c5376784c64ca05b691c4686ecd.jpg'
FROM suppliers s WHERE s.name = 'MOKO'
ON DUPLICATE KEY UPDATE commercial_name = 'MOKO W6R', device_type = 'bracelet',
    image_path = '/model-images/78888c5376784c64ca05b691c4686ecd.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'MECS-PRO', 'MONIT MECS Pro', 'diaper_sensor', '/model-images/c7a8992a69d659ef06e853f6befecd42.jpg'
FROM suppliers s WHERE s.name = 'MONIT'
ON DUPLICATE KEY UPDATE commercial_name = 'MONIT MECS Pro', device_type = 'diaper_sensor',
    image_path = '/model-images/c7a8992a69d659ef06e853f6befecd42.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'RD-V1', 'W01', 'radar', '/model-images/2a87616691f4878b9ac4f8cfd816a615.jpg'
FROM suppliers s WHERE s.name = 'Qinglanst'
ON DUPLICATE KEY UPDATE commercial_name = 'W01', device_type = 'radar',
    image_path = '/model-images/2a87616691f4878b9ac4f8cfd816a615.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'L08 Pro', 'R05', 'watch', '/model-images/019cb6bcc40ef15ffe98a2f4ca1d2679.jpg'
FROM suppliers s WHERE s.name = 'Vivistar'
ON DUPLICATE KEY UPDATE commercial_name = 'R05', device_type = 'watch',
    image_path = '/model-images/019cb6bcc40ef15ffe98a2f4ca1d2679.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'VL16P', 'R04', 'watch', '/model-images/45465accf3d7b8c10279225d089cf227.jpg'
FROM suppliers s WHERE s.name = 'Vivistar'
ON DUPLICATE KEY UPDATE commercial_name = 'R04', device_type = 'watch',
    image_path = '/model-images/45465accf3d7b8c10279225d089cf227.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'VL17', 'R03', 'watch', '/model-images/c27707e761813389512c25a4050a3b85.jpg'
FROM suppliers s WHERE s.name = 'Vivistar'
ON DUPLICATE KEY UPDATE commercial_name = 'R03', device_type = 'watch',
    image_path = '/model-images/c27707e761813389512c25a4050a3b85.jpg';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'W812', 'W812', 'ncs', ''
FROM suppliers s WHERE s.name = 'Voerka'
ON DUPLICATE KEY UPDATE commercial_name = 'W812', device_type = 'ncs';

INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, 'HW20PRO', 'HW20PRO', 'watch', '/model-images/eed091a62f83e3ef03c7090ce09ea262.jpg'
FROM suppliers s WHERE s.name = 'Wonlex'
ON DUPLICATE KEY UPDATE commercial_name = 'HW20PRO', device_type = 'watch',
    image_path = '/model-images/eed091a62f83e3ef03c7090ce09ea262.jpg';

INSERT IGNORE INTO supplier_device_types (supplier_id, device_type)
SELECT s.id, t.device_type FROM suppliers s JOIN (
    SELECT '4P Touch' AS name, 'watch' AS device_type
    UNION ALL SELECT 'Vivistar', 'watch'
    UNION ALL SELECT 'Wonlex', 'watch'
    UNION ALL SELECT 'Voerka', 'ncs'
    UNION ALL SELECT 'Qinglanst', 'radar'
    UNION ALL SELECT 'MOKO', 'gateway'
    UNION ALL SELECT 'MOKO', 'bracelet'
    UNION ALL SELECT 'MONIT', 'diaper_sensor'
) t ON t.name = s.name;

INSERT IGNORE INTO whitelist (imei, supplier, model, device_type, license_id, sim_number, device_id, company) VALUES
    ('351266770073676', '4P Touch', 'Y6M', 'watch', 1, '+351962621694', '6677007367', 'havicare'),
    ('637507597567372', '4P Touch', 'D46', 'watch', 0, '+351962621781', '0759756737', 'null'),
    ('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1, '+351962621789', '', 'havicare'),
    ('861265061009830', 'Vivistar', 'L08 Pro', 'watch', 1, '', '', 'havicare'),
    ('861265061274392', 'Vivistar', 'VL16P', 'watch', 1001, '+351962621844', '', 'hitcare'),
    ('861265061323462', 'Vivistar', 'VL16P', 'watch', 1001, '+351962621730', '6506132346', 'hitcare'),
    ('861265061386014', 'Vivistar', 'VL17', 'watch', 0, '+351962621635', '', 'null'),
    ('861265062542599', 'Vivistar', 'VL17', 'watch', 1, '', '', 'havicare'),
    ('861265062542615', 'Vivistar', 'VL17', 'watch', 0, '+351962621803', '', 'null'),
    ('861265062544868', 'Vivistar', 'VL16P', 'watch', 1, '+351962621463', '', 'havicare'),
    ('861728087056333', '4P Touch', 'Y6S', 'watch', 1, '', '2808705633', 'havicare'),
    ('861728087060467', '4P Touch', 'D44S', 'watch', 1, '', '2808706046', 'havicare'),
    ('861728087743062', '4P Touch', 'D41', 'watch', 1, '+351962621664', '2808774306', 'havicare'),
    ('863737079757376', '4P Touch', 'D46', 'watch', 1001, '+351962621781', '3707975737', 'null'),
    ('868160060298224', '4P Touch', 'D45 Pro', 'watch', 0, '', '6006029822', 'null'),
    ('868705080304889', 'Wonlex', 'HW20PRO', 'watch', 1, '', '', 'havicare'),
    ('868705080304962', 'Wonlex', 'HW20PRO', 'watch', 1, '', '', 'havicare'),
    ('bea6c3dd8e02', 'Voerka', 'W812', 'ncs', 1001, '', 'bea6c3dd8e02', 'hitcare'),
    ('594B3CF100A7', 'Qinglanst', 'RD-V1', 'radar', 1001, '', '594B3CF100A7', 'hitcare'),
    ('9D8A3204F853', 'Qinglanst', 'RD-V1', 'radar', 1001, '', '9D8A3204F853', 'hitcare'),
    ('AD8A613B0493', 'Qinglanst', 'RD-V1', 'radar', 1001, '', 'AD8A613B0493', 'hitcare'),
    ('c5e390f30bce', 'MOKO', 'MKGW4', 'gateway', 1001, '', 'c5e390f30bce', 'hitcare'),
    ('d48c49f7909c', 'MOKO', 'MKGW3', 'gateway', 1001, '', 'd48c49f7909c', 'hitcare'),
    ('dc1603ecf1f7', 'MOKO', 'MKGW4', 'gateway', 1001, '', 'dc1603ecf1f7', 'hitcare'),
    ('eec5000202f9', 'MONIT', 'MECS-PRO', 'diaper_sensor', 1001, '', 'eec5000202f9', 'hitcare'),
    ('fbd87c59ba8b', 'MOKO', 'W6R', 'bracelet', 1001, '', 'fbd87c59ba8b', 'hitcare');

INSERT IGNORE INTO gateway_device_links (gateway_device_key, linked_device_key, enabled) VALUES
    ('c5e390f30bce', 'eec5000202f9', 1),
    ('d48c49f7909c', 'eec5000202f9', 1),
    ('dc1603ecf1f7', 'eec5000202f9', 1),
    ('c5e390f30bce', 'fbd87c59ba8b', 1),
    ('d48c49f7909c', 'fbd87c59ba8b', 1),
    ('dc1603ecf1f7', 'fbd87c59ba8b', 1);

-- Os overrides de capacidades do HW20PRO: o único sítio em que produção discorda do que o
-- catálogo semeado produz. São escolhas feitas à mão no separador das Capacidades, e estas
-- instruções reproduzem o estado final delas.
UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
JOIN capabilities c ON c.id = mc.capability_id
SET mc.is_requestable = 1
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND c.capability_key IN ('breath_rate', 'heart_rate', 'location', 'temperature');

UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
JOIN capabilities c ON c.id = mc.capability_id
SET mc.is_requestable = 0
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND c.capability_key IN ('ecg', 'hrv', 'ppg', 'rr_interval');

UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
JOIN capabilities c ON c.id = mc.capability_id
SET mc.enabled = 0
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND c.capability_key IN ('breath_rate', 'temperature');
