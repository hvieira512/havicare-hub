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
SELECT c.id, l.license_id, l.name FROM companies c JOIN (
    SELECT 'havicare' AS company, 1 AS license_id, 'hc.dev' AS name
    UNION ALL SELECT 'havicare', 22, 'hc2.dev'
    UNION ALL SELECT 'hitcare', 1001, 'gucc.dev'
) l ON l.company = c.name
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- A W6 e a W6B são a mesma pulseira, com e sem botão macio, por isso partilham a imagem. O
-- W812 não tem imagem, e é por isso que a actualização não deixa uma imagem vazia apagar a
-- que lá esteja: uma trocada no painel não se perde por o seed não trazer nenhuma.
INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
SELECT s.id, m.internal_model, m.commercial_name, m.device_type, m.image_path
FROM suppliers s JOIN (
    SELECT '4P Touch' AS supplier, 'D41' AS internal_model, 'D41' AS commercial_name,
           'watch' AS device_type, '/model-images/9201181e4f07060bd5ded5e48ca8e20a.jpg' AS image_path
    UNION ALL SELECT '4P Touch',  'D44S',             'R05',                        'watch',         '/model-images/be4e5160e602a993f519011e6c9f796c.jpg'
    UNION ALL SELECT '4P Touch',  'D45 Pro',          'D45 Pro',                    'watch',         '/model-images/4a088f59242d03d7023d5e51d4da8e49.jpg'
    UNION ALL SELECT '4P Touch',  'D46',              'R04',                        'watch',         '/model-images/1347f078cd3c213a48495d8f1a366713.jpg'
    UNION ALL SELECT '4P Touch',  'Y6M',              'Y6M',                        'watch',         '/model-images/9648d481eb3381148ea91c84aba2687c.jpg'
    UNION ALL SELECT '4P Touch',  'Y6S',              'R03',                        'watch',         '/model-images/3d48c42e589923177a1ac3ed147758e0.jpg'
    UNION ALL SELECT 'MOKO',      'MKGW3',            'MOKOSmart MKGW3',            'gateway',       '/model-images/45bee5a0028156faa71ff5c6c081b6d7.jpg'
    UNION ALL SELECT 'MOKO',      'MKGW4',            'MOKOSmart MKGW4',            'gateway',       '/model-images/3bbccf9f4d8e4830480adf834cdfd278.jpg'
    UNION ALL SELECT 'MOKO',      'MKGW-mini 03-20D', 'MOKOSmart MKGW-mini 03-20D', 'gateway',       '/model-images/a8b0f419d117411508270b342869add0.jpg'
    UNION ALL SELECT 'MOKO',      'W6B',              'MOKO W6B',                   'bracelet',      '/model-images/78888c5376784c64ca05b691c4686ecd.jpg'
    UNION ALL SELECT 'MOKO',      'W6',               'MOKO W6',                    'bracelet',      '/model-images/78888c5376784c64ca05b691c4686ecd.jpg'
    UNION ALL SELECT 'MONIT',     'MECS-PRO',         'MONIT MECS Pro',             'diaper_sensor', '/model-images/c7a8992a69d659ef06e853f6befecd42.jpg'
    UNION ALL SELECT 'Qinglanst', 'RD-V1',            'W01',                        'radar',         '/model-images/2a87616691f4878b9ac4f8cfd816a615.jpg'
    UNION ALL SELECT 'Vivistar',  'L08 Pro',          'R05',                        'watch',         '/model-images/019cb6bcc40ef15ffe98a2f4ca1d2679.jpg'
    UNION ALL SELECT 'Vivistar',  'VL16P',            'R04',                        'watch',         '/model-images/45465accf3d7b8c10279225d089cf227.jpg'
    UNION ALL SELECT 'Vivistar',  'VL17',             'R03',                        'watch',         '/model-images/c27707e761813389512c25a4050a3b85.jpg'
    UNION ALL SELECT 'Voerka',    'W812',             'W812',                       'ncs',           ''
    UNION ALL SELECT 'Wonlex',    'HW20PRO',          'HW20PRO',                    'watch',         '/model-images/eed091a62f83e3ef03c7090ce09ea262.jpg'
) m ON m.supplier = s.name
ON DUPLICATE KEY UPDATE
    commercial_name = VALUES(commercial_name),
    device_type = VALUES(device_type),
    image_path = IF(VALUES(image_path) = '', models.image_path, VALUES(image_path));

-- Os pares fornecedor x tipo de dispositivo não se semeiam: saem dos modelos acima.

-- Um dispositivo sem dono tem `NULL` nas duas colunas, e não o `0` e o `'null'`.
--
-- Esses dois são os sentinelas de memória: é como o hub diz "sem licença" e "sem empresa"
-- enquanto o valor viaja, e é o que o ficheiro da whitelist escreve. Na base convertem-se,
-- e o `WhitelistRepository` tem uma função para cada -- `storedLicenseId()` e
-- `storedCompany()` -- precisamente para eles não chegarem aqui.
--
-- O seed saltava essa fronteira e gravava os sentinelas em cru. O resultado era visível: o
-- filtro de licenças mostrava uma empresa chamada "Sem empresa" com uma licença "Sem
-- Licença" lá dentro, em vez do "Sem licença" solto que a produção mostra -- porque uma
-- empresa cujo nome é o texto `null` é, para todo o resto do sistema, uma empresa a sério.
INSERT IGNORE INTO whitelist (imei, supplier, model, device_type, license_id, sim_number, device_id, company) VALUES
    ('351266770073676', '4P Touch', 'Y6M', 'watch', 1, '+351962621694', '6677007367', 'havicare'),
    ('637507597567372', '4P Touch', 'D46', 'watch', NULL, '+351962621781', '0759756737', NULL),
    ('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1, '+351962621789', '', 'havicare'),
    ('861265061009830', 'Vivistar', 'L08 Pro', 'watch', 1, '', '', 'havicare'),
    ('861265061274392', 'Vivistar', 'VL16P', 'watch', 1001, '+351962621844', '', 'hitcare'),
    ('861265061323462', 'Vivistar', 'VL16P', 'watch', 1001, '+351962621730', '6506132346', 'hitcare'),
    ('861265061386014', 'Vivistar', 'VL17', 'watch', NULL, '+351962621635', '', NULL),
    ('861265062542599', 'Vivistar', 'VL17', 'watch', 1, '', '', 'havicare'),
    ('861265062542615', 'Vivistar', 'VL17', 'watch', NULL, '+351962621803', '', NULL),
    ('861265062544868', 'Vivistar', 'VL16P', 'watch', 1, '+351962621463', '', 'havicare'),
    ('861728087056333', '4P Touch', 'Y6S', 'watch', 1, '', '2808705633', 'havicare'),
    ('861728087060467', '4P Touch', 'D44S', 'watch', 1, '', '2808706046', 'havicare'),
    ('861728087743062', '4P Touch', 'D41', 'watch', 1, '+351962621664', '2808774306', 'havicare'),
    -- Tinha a licença 1001 e a empresa a `null`, que é combinação impossível: uma licença
    -- não existe sem a empresa a que pertence. A 1001 é da hitcare, e é essa a leitura que
    -- não deita fora informação -- a alternativa era largar a licença e deixá-lo sem dono.
    ('863737079757376', '4P Touch', 'D46', 'watch', 1001, '+351962621781', '3707975737', 'hitcare'),
    ('868160060298224', '4P Touch', 'D45 Pro', 'watch', NULL, '', '6006029822', NULL),
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
    ('fbd87c59ba8b', 'MOKO', 'W6B', 'bracelet', 1001, '', 'fbd87c59ba8b', 'hitcare');

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
