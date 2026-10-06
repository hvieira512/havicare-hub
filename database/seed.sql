-- O inventário de dispositivos, capturado do hub de produção (sockets.hitcare.net).
--
-- Cada instrução é idempotente e resolve os ids por chave natural; os dispositivos e as
-- ligações a gateways usam INSERT IGNORE, para não pisar edições feitas à mão.
--
-- Não se semeiam segredos nem estado vivo: api_users, device_configurations,
-- device_configuration_changes/operations, private_radio_map_access_points e dashboard_notifications.

-- As empresas ficam porque a licença logo a seguir junta-se a elas pelo nome.
INSERT IGNORE INTO companies (name) VALUES
    ('havicare'),
    ('hitcare');

INSERT INTO licenses (company_id, license_id, name)
SELECT c.id, l.license_id, l.name FROM companies c JOIN (
    SELECT 'havicare' AS company, 1 AS license_id, 'hc.dev' AS name
    UNION ALL SELECT 'havicare', 22, 'hc2.dev'
    UNION ALL SELECT 'havicare', 24, 'besenior.havicare'
    UNION ALL SELECT 'havicare', 25, 'demo.havicare'
    UNION ALL SELECT 'hitcare', 1001, 'gucc.dev'
    UNION ALL SELECT 'hitcare', 2004, 'gerpi.cvp-chelvas'
    UNION ALL SELECT 'hitcare', 2051, 'gerpi1.emeis-pt'
    UNION ALL SELECT 'hitcare', 2103, 'gerpi1.casabrancaresidencial'
) l ON l.company = c.name
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Os fornecedores e os modelos não se semeiam aqui: escreve-os o `ReferenceCatalogSeeder`.

-- Os pares fornecedor x tipo de dispositivo não se semeiam: saem dos modelos.

-- Um dispositivo sem dono tem `NULL` nas duas colunas: o `0` e o `'null'` são sentinelas de
-- memória, que o `WhitelistRepository` converte na fronteira.
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
    -- A 1001 é da hitcare: uma licença não existe sem a empresa a que pertence.
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

-- Os overrides de capacidades do HW20PRO, o único sítio em que a produção discorda do catálogo
-- semeado: escolhas feitas à mão no separador das Capacidades.
UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
SET mc.is_requestable = 1
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND mc.capability_key IN ('breath_rate', 'heart_rate', 'location', 'temperature');

UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
SET mc.is_requestable = 0
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND mc.capability_key IN ('ecg', 'hrv', 'ppg', 'rr_interval');

UPDATE model_capabilities mc
JOIN models m ON m.id = mc.model_id
JOIN suppliers s ON s.id = m.supplier_id
SET mc.enabled = 0
WHERE s.name = 'Wonlex' AND m.internal_model = 'HW20PRO'
  AND mc.capability_key IN ('breath_rate', 'temperature');
